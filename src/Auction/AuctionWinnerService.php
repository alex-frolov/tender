<?php

declare(strict_types=1);

namespace App\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Exception\AuctionNotFoundException;
use App\Auction\Exception\AuctionWinnerException;
use App\Auction\Repository\AuctionRepository;
use App\Auction\Service\WinnerTransaction;
use App\Auction\State\AuctionStateService;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Exception\StateTransitionException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Выбор победителя аукциона (FR-1.3.5, UC-13a/UC-14).
 *
 * Завершение торгов и фиксация победителя:
 * - авто (REDUCTION): победитель — принятая ставка с минимальной ценой
 *   (каноническая база, PR-6). Flow: TRADE → CHOICE (FINISH, T16) →
 *   APPROVE (APPROVE_WINNER, T23) с фиксацией победителя;
 * - ручной (FREE_PRICE/PRICE_REQUEST): после закрытия окна (CHOICE) заказчик
 *   выбирает принятое предложение (UC-13a) → APPROVE. Отклонённая ставка
 *   (status=rejected) или предложение вне аукциона победителем быть не может;
 * - finish(): завершение торгов (TRADE → CHOICE) — шаг перед ручным выбором
 *   или авто-выбором; фиксирует actual_end_at и событие auction.finished.
 *
 * Сервис отвечает за оркестрацию и валидацию (статусы, режим выбора, T16-
 * завершение торгов с аудитом/outbox auction.finished). Транзакционный «хвост»
 * выбора победителя (фиксация winner_bid_id, статусы заявок winning/lost,
 * переход APPROVE_WINNER, аудит PR-9, outbox auction.winner_chosen, tenant-
 * проверка, pessimistic lock) вынесен во внутренний support-класс
 * `Auction\Service\WinnerTransaction`.
 *
 * Tenant-изоляция (AGENTS.md): завершение/выбор выполняет только заказчик —
 * тенант аукциона (= customerId тендера); чужой актор получает 404
 * (AuctionNotFoundException). Актёр передаётся как User; null = система
 * (расписание/авто-выбор), tenant-проверка не выполняется, аудит — system.
 *
 * При выборе победителя:
 * - auctions.winner_bid_id = id победившей ставки (auction_bids.id);
 * - lots.winner_bid_id = id заявки победителя (bids.id, data-model.md);
 * - bids.status: победителю → winning, остальным допущенным → lost;
 * - событие auction.winner_chosen (auction_id, supplier_id, price_minor,
 *   basis, vat_rate, mode) + аудит (PR-9: цена/базис/ставка в канонической базе);
 * - Redis-снапшот обновляется ПОСЛЕ коммита (FR-1.3.6).
 *
 * Гонка «ставка vs завершение» (domain/auction-state-machine.md, раздел 7):
 * операция выполняется под pessimistic lock строки аукциона (SELECT ... FOR
 * UPDATE) — конкурентная ставка дожидается коммита, двойной победитель
 * невозможен (после CHOICE ставки не принимаются).
 *
 * Формирование протокола — через плагин DocumentGenerator (FR-1.2.8),
 * в ядре не генерируется.
 */
final readonly class AuctionWinnerService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private AuctionRepository $auctions,
        private AuctionStateService $state,
        private WinnerTransaction $transaction,
        #[Autowire(service: 'state_machine.auction')]
        private WorkflowInterface $auctionWorkflow,
    ) {
    }

    /**
     * Завершение торгов (T16, TRADE → CHOICE): торги остановлены (таймер истёк
     * / ручной стоп заказчика), окно закрыто, ставки больше не принимаются.
     * Фиксирует actual_end_at; событие auction.finished (winner_bid_id — лучшая
     * принятая ставка, если есть; final_price_minor; rounds_count).
     *
     * Для FREE_PRICE/PRICE_REQUEST — шаг перед ручным выбором победителя
     * (UC-13a); для REDUCTION — перед авто-выбором (может быть вызван
     * автоматически из selectWinnerAutomatic).
     *
     * @param User|null               $actor заказчик (tenancy) или null = система
     * @param \DateTimeImmutable|null $now   момент завершения (UTC)
     * @param string|null             $ip    IP инициатора (аудит)
     *
     * @throws AuctionNotFoundException если актор не тенант аукциона (404)
     * @throws StateTransitionException если аукцион не в TRADE (завершение недопустимо)
     */
    public function finish(
        Auction $auction,
        ?User $actor = null,
        ?\DateTimeImmutable $now = null,
        ?string $ip = null,
    ): Auction {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->transaction->assertCustomer($auction, $actor);

        $result = $this->em->wrapInTransaction(function () use ($auction, $actor, $now, $ip): Auction {
            $locked = $this->transaction->lockAuction($auction->getId());

            if (AuctionStatusEnum::TRADE !== $locked->getStatus()) {
                throw new StateTransitionException('Only trading auctions can be finished');
            }

            $best = $this->auctions->findBestAcceptedBid($locked);
            $locked->setActualEndAt($now);
            $this->auctionWorkflow->apply($locked, AuctionStatusTransition::FINISH->value);
            $this->em->flush();

            $this->audit->record(
                action: 'auction.finished',
                entityType: 'auction',
                entityId: (string) $locked->getId(),
                tenantId: (string) $locked->getTenantId(),
                actorType: null !== $actor ? 'user' : 'system',
                actorId: null !== $actor ? (string) $actor->getId() : null,
                before: ['status' => AuctionStatusEnum::TRADE->value],
                after: [
                    'status' => AuctionStatusEnum::CHOICE->value,
                    'actual_end_at' => $now->format('Y-m-d\TH:i:s\Z'),
                    'final_price_minor' => $locked->getCurrentPriceMinor(),
                    'rounds_count' => $this->auctions->countAcceptedBids($locked),
                ],
                ip: $ip,
            );

            $this->em->persist(new OutboxEvent(
                eventType: 'auction.finished',
                payload: [
                    'auction_id' => (string) $locked->getId(),
                    'winner_bid_id' => null !== $best ? (string) $best->getId() : null,
                    'final_price_minor' => $locked->getCurrentPriceMinor(),
                    'rounds_count' => $this->auctions->countAcceptedBids($locked),
                ],
                aggregateType: 'auction',
                aggregateId: (string) $locked->getId(),
                tenantId: (string) $locked->getTenantId(),
            ));
            $this->em->flush();

            return $locked;
        });

        // FR-1.3.6: Redis-снапшот — после коммита.
        $this->state->write($result);

        return $result;
    }

    /**
     * Авто-выбор победителя (FR-1.3.5, REDUCTION): минимальная цена.
     *
     * Одна операция покрывает весь цикл: если аукцион ещё в TRADE — сначала
     * завершение торгов (FINISH, T16 + событие auction.finished), затем
     * выбор победителя (APPROVE_WINNER, T23) + событие auction.winner_chosen
     * (mode=auto). Победитель — принятая ставка с минимальной ценой
     * (каноническая база, PR-6); при равенстве — более ранняя по времени.
     *
     * @param User|null               $actor заказчик (tenancy) или null = система
     * @param \DateTimeImmutable|null $now   момент (UTC)
     * @param string|null             $ip    IP инициатора (аудит)
     *
     * @throws AuctionWinnerException   если аукцион не REDUCTION (wrong_auction_type)
     *                                  или нет принятых ставок (no_winner —
     *                                  торги без результата, дальнейший путь — EXPIRED)
     * @throws AuctionNotFoundException если актор не тенант аукциона (404)
     * @throws StateTransitionException если аукцион вне TRADE/CHOICE
     */
    public function selectWinnerAutomatic(
        Auction $auction,
        ?User $actor = null,
        ?\DateTimeImmutable $now = null,
        ?string $ip = null,
    ): Auction {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->transaction->assertCustomer($auction, $actor);

        if (AuctionTypeEnum::REDUCTION !== $auction->getType()) {
            throw new AuctionWinnerException('Automatic winner selection applies to REDUCTION auctions only (FR-1.3.5)', 'wrong_auction_type');
        }

        $result = $this->em->wrapInTransaction(function () use ($auction, $actor, $now, $ip): Auction {
            $locked = $this->transaction->lockAuction($auction->getId());

            if (AuctionStatusEnum::TRADE === $locked->getStatus()) {
                // T16: завершение торгов (окно закрыто) + auction.finished.
                $best = $this->auctions->findBestAcceptedBid($locked);
                $locked->setActualEndAt($now);
                $this->auctionWorkflow->apply($locked, AuctionStatusTransition::FINISH->value);
                $this->em->flush();
                $this->audit->record(
                    action: 'auction.finished',
                    entityType: 'auction',
                    entityId: (string) $locked->getId(),
                    tenantId: (string) $locked->getTenantId(),
                    actorType: null !== $actor ? 'user' : 'system',
                    actorId: null !== $actor ? (string) $actor->getId() : null,
                    before: ['status' => AuctionStatusEnum::TRADE->value],
                    after: [
                        'status' => AuctionStatusEnum::CHOICE->value,
                        'actual_end_at' => $now->format('Y-m-d\TH:i:s\Z'),
                    ],
                    ip: $ip,
                );
                $this->em->persist(new OutboxEvent(
                    eventType: 'auction.finished',
                    payload: [
                        'auction_id' => (string) $locked->getId(),
                        'winner_bid_id' => null !== $best ? (string) $best->getId() : null,
                        'final_price_minor' => $locked->getCurrentPriceMinor(),
                        'rounds_count' => $this->auctions->countAcceptedBids($locked),
                    ],
                    aggregateType: 'auction',
                    aggregateId: (string) $locked->getId(),
                    tenantId: (string) $locked->getTenantId(),
                ));
                $this->em->flush();
            }

            if (AuctionStatusEnum::CHOICE !== $locked->getStatus()) {
                throw new StateTransitionException('Winner can be selected only after trading finished (CHOICE)');
            }

            $winningBid = $this->auctions->findBestAcceptedBid($locked);
            if (null === $winningBid) {
                throw new AuctionWinnerException('No accepted bids to select a winner (auction has no result)', 'no_winner');
            }

            return $this->transaction->chooseWinner($locked, $winningBid, 'auto', $now, $ip, $actor);
        });

        // FR-1.3.6: Redis-снапшот — после коммита.
        $this->state->write($result);

        return $result;
    }

    /**
     * Ручной выбор победителя (FR-1.3.5, UC-13a): FREE_PRICE/PRICE_REQUEST.
     *
     * Только из CHOICE (окно закрыто через finish/T16): заказчик указывает
     * принятое предложение (auction_bids.id) → CHOICE → APPROVE (APPROVE_WINNER,
     * T23) + событие auction.winner_chosen (mode=manual). Отклонённая ставка
     * (status=rejected) или предложение вне аукциона победителем быть не может.
     *
     * @param User|null               $actor заказчик (tenancy) или null = система
     * @param \DateTimeImmutable|null $now   момент (UTC)
     * @param string|null             $ip    IP инициатора (аудит)
     *
     * @throws AuctionWinnerException   если указанная ставка не является принятым
     *                                  предложением этого аукциона (invalid_winner_bid)
     * @throws AuctionNotFoundException если актор не тенант аукциона (404)
     * @throws StateTransitionException если аукцион не в CHOICE
     */
    public function selectWinnerManual(
        Auction $auction,
        Uuid $auctionBidId,
        ?User $actor = null,
        ?\DateTimeImmutable $now = null,
        ?string $ip = null,
    ): Auction {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->transaction->assertCustomer($auction, $actor);

        $result = $this->em->wrapInTransaction(function () use ($auction, $auctionBidId, $actor, $now, $ip): Auction {
            $locked = $this->transaction->lockAuction($auction->getId());

            if (AuctionStatusEnum::CHOICE !== $locked->getStatus()) {
                throw new StateTransitionException('Winner can be selected only in CHOICE (finish trading first)');
            }

            $winningBid = $this->auctions->findAcceptedBid($locked, $auctionBidId);
            if (null === $winningBid) {
                throw new AuctionWinnerException('Bid is not an accepted proposal of this auction (FR-1.3.5)', 'invalid_winner_bid');
            }

            return $this->transaction->chooseWinner($locked, $winningBid, 'manual', $now, $ip, $actor);
        });

        // FR-1.3.6: Redis-снапшот — после коммита.
        $this->state->write($result);

        return $result;
    }
}
