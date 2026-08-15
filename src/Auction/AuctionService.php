<?php

declare(strict_types=1);

namespace App\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Repository\AuctionRepository;
use App\Auction\Rules\RulesSnapshot;
use App\Auction\Rules\RulesSnapshotFactory;
use App\Auction\State\AuctionStateService;
use App\Infrastructure\Metrics\AuctionMetricsCollector;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Exception\StateTransitionException;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Аукционы: запуск торгов (UC-12, T13) — точка входа «старт».
 *
 * Последовательность старта:
 * 1. Захват правил (rules_snapshot, PR-9) через RulesSnapshotFactory —
 *    «правила из плагина» фиксируются при входе в TRADE и не меняются;
 * 2. Старт таймера: started_at = now, planned_end_at = now + step_duration_sec
 *    (FR-1.3.1); дальнейшие продления — антиснайпинг (AuctionTimer, FR-1.3.3);
 * 3. Переход SCHEDULED → TRADE через symfony/workflow (guard требует
 *    зафиксированный rules_snapshot — порядок обязателен).
 *
 * Пауза/возобновление (T20/T21, UC-15):
 * - pause(): TRADE → PAUSED, таймер заморожен (paused_remaining_sec в БД —
 *   источник истины, переживает сбой Redis), событие auction.paused;
 * - resume(): PAUSED → TRADE, таймер продолжен с остатка
 *   (planned_end_at = now + paused_remaining_sec), событие auction.resumed;
 * - autoPauseStale(): авто-пауза TRADE-аукционов, чей heartbeat в Redis
 *   отсутствует/простоил дольше порога AUCTION_HEARTBEAT_TIMEOUT (UC-15):
 *   восстановление после сбоя Redis/RabbitMQ — ставки целы (источник PG).
 *
 * Аудит (FR-1.8) + outbox-события auction.* (domain/events.md).
 */
final readonly class AuctionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private RulesSnapshotFactory $rulesFactory,
        private AuctionStateService $state,
        private AuctionRepository $auctions,
        private TenderReadService $tenders,
        private AuctionMetricsCollector $auctionMetrics,
        #[Autowire(service: 'state_machine.auction')]
        private WorkflowInterface $auctionWorkflow,
    ) {
    }

    /**
     * Запуск торгов (T13, SCHEDULED → TRADE).
     *
     * @throws StateTransitionException если аукцион не в SCHEDULED или правила
     *                                  уже зафиксированы (workflow-переход
     *                                  не применим)
     */
    public function startTrading(Auction $auction, ?\DateTimeImmutable $now = null, ?string $ip = null): Auction
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Только из SCHEDULED (guard start_trade требует rules_snapshot, а он
        // захватывается именно здесь — поэтому статус проверяем явно).
        if (AuctionStatusEnum::SCHEDULED !== $auction->getStatus()) {
            throw new StateTransitionException('Only scheduled auctions can start trading');
        }

        // Валюта снапшота — из тендера (Auction её не хранит, PR-9): грузим
        // Tender через публичный read-контракт Tender-модуля.
        $tender = $this->tenders->resolveTender((string) $auction->getTenderId());
        $auction->captureRulesSnapshot($this->rulesFactory->create($auction, $tender->getCurrency()));
        $auction->setStartedAt($now);
        $auction->setPlannedEndAt($now->add(new \DateInterval('PT'.$auction->getStepDurationSec().'S')));
        // Переход применим: guard start_trade (rules_snapshot !== null) пройден.
        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::START_TRADE->value);
        $this->em->flush();

        $rules = $auction->getRulesSnapshot();
        $snapshot = null !== $rules ? RulesSnapshot::fromArray($rules) : null;

        $this->audit->record(
            action: 'auction.started',
            entityType: 'auction',
            entityId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
            actorType: 'system',
            after: [
                'status' => AuctionStatusEnum::TRADE->value,
                'started_at' => $now->format('Y-m-d\TH:i:s\Z'),
                'planned_end_at' => $auction->getPlannedEndAt()?->format('Y-m-d\TH:i:s\Z'),
                'start_price_minor' => $auction->getStartPriceMinor(),
                'rules_snapshot' => $rules,
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'auction.started',
            payload: [
                'auction_id' => (string) $auction->getId(),
                'start_price_minor' => $auction->getStartPriceMinor(),
                'bid_step' => $snapshot?->bidStepMinor,
                'step_duration' => $auction->getStepDurationSec(),
                'planned_end_at' => $auction->getPlannedEndAt()?->format('Y-m-d\TH:i:s\Z'),
            ],
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
        ));
        $this->em->flush();

        // FR-1.3.6: Redis-снапшот live-состояния при входе в TRADE
        // (старт торгов) — query path читает из кэша, БД не трогается.
        $this->state->write($auction);

        return $auction;
    }

    /**
     * TRADE-аукционы (живые торги). Для heartbeat-команды (auctions:heartbeat)
     * и авто-паузы по простою (autoPauseStale, UC-15).
     *
     * @return list<Auction>
     */
    public function auctionsInTrade(): array
    {
        return $this->auctions->listTrading();
    }

    /**
     * Пауза торгов (T20, TRADE → PAUSED): таймер заморожен, остаток сохраняется
     * в БД (paused_remaining_sec — источник истины, переживает сбой Redis, UC-15).
     * Ставки не принимаются (AuctionStatusEnum::acceptsBids() = только TRADE).
     *
     * Событие auction.paused (domain/events.md): auction_id, reason, remaining_sec.
     *
     * @param \DateTimeImmutable|null $now    момент паузы (UTC)
     * @param string                  $reason причина паузы (инцидент/тех. пауза/
     *                                        heartbeat_timeout/…)
     * @param string|null             $ip     IP инициатора (аудит)
     *
     * @throws StateTransitionException если аукцион не в TRADE (пауза недопустима)
     */
    public function pause(Auction $auction, string $reason, ?\DateTimeImmutable $now = null, ?string $ip = null): Auction
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (AuctionStatusEnum::TRADE !== $auction->getStatus()) {
            throw new StateTransitionException('Only trading auctions can be paused');
        }

        $plannedEnd = $auction->getPlannedEndAt();
        $remaining = null !== $plannedEnd
            ? max(0, $plannedEnd->getTimestamp() - $now->getTimestamp())
            : 0;

        $auction->setPausedRemainingSec($remaining);
        $auction->setPausedAt($now);
        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::PAUSE->value);
        $this->em->flush();

        $this->audit->record(
            action: 'auction.paused',
            entityType: 'auction',
            entityId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
            actorType: 'system',
            after: [
                'status' => AuctionStatusEnum::PAUSED->value,
                'reason' => $reason,
                'remaining_sec' => $remaining,
                'paused_at' => $now->format('Y-m-d\TH:i:s\Z'),
                'planned_end_at' => $plannedEnd?->format('Y-m-d\TH:i:s\Z'),
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'auction.paused',
            payload: [
                'auction_id' => (string) $auction->getId(),
                'reason' => $reason,
                'remaining_sec' => $remaining,
            ],
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
        ));
        $this->em->flush();

        // Redis-снапшот live-состояния (PAUSED) — query path/SSE.
        $this->state->write($auction);

        // Пауза/возобновление (auction_pauses_total, ops/observability.md §1).
        $this->auctionMetrics->pauseOrResume();

        return $auction;
    }

    /**
     * Возобновление торгов (T21, PAUSED → TRADE): таймер продолжен с остатка
     * (paused_remaining_sec, сохранённого при паузе в БД): new planned_end_at =
     * now + paused_remaining_sec. Событие auction.resumed (auction_id, new_end_at).
     *
     * @throws StateTransitionException если аукцион не в PAUSED или нет сохранённого
     *                                  остатка (возобновление недопустимо)
     */
    public function resume(Auction $auction, ?\DateTimeImmutable $now = null, ?string $ip = null): Auction
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (AuctionStatusEnum::PAUSED !== $auction->getStatus()) {
            throw new StateTransitionException('Only paused auctions can be resumed');
        }

        $remaining = $auction->getPausedRemainingSec();
        if (null === $remaining) {
            throw new StateTransitionException('Cannot resume: paused remaining time is missing');
        }

        $newEnd = $now->add(new \DateInterval('PT'.$remaining.'S'));
        $auction->setPlannedEndAt($newEnd);
        $auction->setPausedRemainingSec(null);
        $auction->setPausedAt(null);
        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::RESUME->value);
        $this->em->flush();

        $this->audit->record(
            action: 'auction.resumed',
            entityType: 'auction',
            entityId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
            actorType: 'system',
            after: [
                'status' => AuctionStatusEnum::TRADE->value,
                'new_end_at' => $newEnd->format('Y-m-d\TH:i:s\Z'),
                'remaining_sec' => $remaining,
                'resumed_at' => $now->format('Y-m-d\TH:i:s\Z'),
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'auction.resumed',
            payload: [
                'auction_id' => (string) $auction->getId(),
                'new_end_at' => $newEnd->format('Y-m-d\TH:i:s\Z'),
            ],
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
        ));
        $this->em->flush();

        // Redis-снапшот live-состояния (TRADE + обновлённый planned_end_at).
        $this->state->write($auction);

        // Пауза/возобновление (auction_pauses_total, ops/observability.md §1).
        $this->auctionMetrics->pauseOrResume();

        return $auction;
    }

    /**
     * Авто-пауза «молчащих» TRADE-аукционов (UC-15, FR-1.3.6):
     * после сбоя Redis/RabbitMQ heartbeat пропадает; аукционы, чей последний
     * heartbeat в Redis старше $heartbeatTimeoutSec (или отсутствует вовсе —
     * простой > порога), переводятся в PAUSED с reason = heartbeat_timeout.
     * Источник истины — PostgreSQL: ни одна принятая ставка не теряется.
     *
     * @return int число авто-приостановленных аукционов
     */
    public function autoPauseStale(int $heartbeatTimeoutSec, ?\DateTimeImmutable $now = null, ?string $ip = null): int
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $paused = 0;
        foreach ($this->auctions->listTrading() as $auction) {
            $idle = $this->state->idleSeconds($auction->getId(), $now);
            // Простой > порога (или heartbeat отсутствует — idle = null).
            if (null === $idle || $idle > $heartbeatTimeoutSec) {
                $this->pause($auction, 'heartbeat_timeout', $now, $ip);
                ++$paused;
            }
        }

        return $paused;
    }
}
