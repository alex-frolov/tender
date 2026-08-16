<?php

declare(strict_types=1);

namespace App\Auction\Service;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Exception\AuctionNotFoundException;
use App\Auction\Repository\AuctionRepository;
use App\Auction\Rules\RulesSnapshot;
use App\Auction\Timer\AuctionTimer;
use App\Infrastructure\Metrics\AuctionMetricsCollector;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Tender\TenderReadService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Транзакционный «хвост» подачи ставки (внутренний support-класс модуля
 * Auction): общая транзакционная механика — pessimistic lock строки аукциона,
 * идемпотентность (replay), запись append-only (auction_bids), обновление
 * current_price_minor, антиснайпинг, аудит арифметики (PR-9) и outbox
 * auction.bid — в одном месте. Вызывается из AuctionBidService внутри активной
 * транзакции (em->wrapInTransaction остаётся в сервисе, где выполняется
 * валидация по типам).
 *
 * Инварианты (FR-1.3.6, ARCH-6): read-modify-write (current_price → валидация →
 * запись) под pessimistic lock строки аукциона; ставка, current_price, аудит и
 * outbox пишутся ОДНОЙ порцией (один flush на ставку, критерий 4.10 «100–200
 * ставок/сек»). Redis-снапшот live-состояния пишется ПОСЛЕ коммита (AuctionStateService),
 * не входит в транзакцию БД.
 */
final readonly class BidTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private AuctionTimer $timer,
        private TenderReadService $tenders,
        private AuctionRepository $auctions,
        private AuctionMetricsCollector $auctionMetrics,
    ) {
    }

    /**
     * Хвост ставки, общий для REDUCTION(fixed/free), FREE_PRICE, PRICE_REQUEST:
     * запись append-only (auction_bids), обновление current_price_minor,
     * антиснайпинг, аудит арифметики (PR-9) и outbox-событие auction.bid.
     * Вызывается внутри активной транзакции после валидации цены.
     */
    public function commitBid(
        Auction $auction,
        RulesSnapshot $snapshot,
        Uuid $bidderId,
        int $priceMinor,
        int $round,
        bool $isFirst,
        ?string $idempotencyKey,
        \DateTimeImmutable $now,
        ?string $ip,
        bool $extendOnBid = true,
    ): AuctionBid {
        $bid = new AuctionBid(
            auction: $auction,
            bidderId: $bidderId,
            round: $round,
            priceMinor: $priceMinor,
            priceDisplayMinor: $priceMinor,
            priceBasis: $auction->getPriceBasis(),
            vatRateBps: $auction->getVatRateBps(),
            isFirstPrice: $isFirst,
            roundingLog: null,
            idempotencyKey: $idempotencyKey,
            placedAt: $now,
        );
        $this->em->persist($bid);

        $beforeEnd = $auction->getPlannedEndAt();
        $beforeCurrent = $auction->getCurrentPriceMinor();
        $beforeStart = $auction->getStartPriceMinor();

        // Первая ставка при no_start_price (FR-1.1.9): фиксация стартовой цены
        // здесь, после фиксации before-значений (для аудита PR-9).
        if ($isFirst) {
            $auction->setStartPriceMinor($priceMinor);
        }

        // REDUCTION: current_price = цена принятой ставки (понижение ≥ шаг/ниже
        // текущей). FREE_PRICE/PRICE_REQUEST (FR-1.3.8): обязательного понижения
        // нет — current_price отслеживает лучшую (минимальную) предложенную цену;
        // ставка выше текущей принимается, но не понижает current_price.
        if (AuctionTypeEnum::FREE_PRICE === $auction->getType()
            || AuctionTypeEnum::PRICE_REQUEST === $auction->getType()) {
            if (null === $beforeCurrent || $priceMinor < $beforeCurrent) {
                $auction->setCurrentPriceMinor($priceMinor);
            }
        } else {
            $auction->setCurrentPriceMinor($priceMinor);
        }

        // Антиснайпинг (FR-1.3.3): ставка в последнем окне продлевает таймер
        // в пределах лимита продлений и границы trade_end_lead_hours. Применим
        // в live-режимах (REDUCTION, FREE_PRICE); для PRICE_REQUEST (без
        // live-шагов) продления нет — окно закрывается по planned_end_at.
        if ($extendOnBid && null !== $beforeEnd) {
            $newEnd = $this->timer->extendOnBid(
                now: $now,
                plannedEndAt: $beforeEnd,
                extensionsCount: $auction->getExtensionsCount(),
                snapshot: $snapshot,
                executionStartAt: $this->lotExecutionStartAt($auction),
            );
            if (null !== $newEnd) {
                $auction->setPlannedEndAt($newEnd);
                $auction->setExtensionsCount($auction->getExtensionsCount() + 1);
                // Антиснайпинг: продление таймера (auction_extensions_total, §1).
                $this->auctionMetrics->extensionHappened();
            }
        }

        $this->audit->record(
            action: 'auction.bid',
            entityType: 'auction',
            entityId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
            actorType: 'user',
            actorId: (string) $bidderId,
            before: [
                'current_price_minor' => $beforeCurrent,
                'start_price_minor' => $beforeStart,
                'planned_end_at' => $beforeEnd?->format('Y-m-d\TH:i:s\Z'),
            ],
            after: [
                'current_price_minor' => $auction->getCurrentPriceMinor(),
                'start_price_minor' => $auction->getStartPriceMinor(),
                'is_first_price' => $isFirst,
                'round' => $round,
                'extensions_count' => $auction->getExtensionsCount(),
                'planned_end_at' => $auction->getPlannedEndAt()?->format('Y-m-d\TH:i:s\Z'),
                'price_basis' => $auction->getPriceBasis()->value,
                'vat_rate_bps' => $auction->getVatRateBps(),
            ],
            ip: $ip,
            // Задача 4.10 (NFR-1: 100–200 ставок/сек): аудит persist-ится без
            // отдельного flush — ставка, аудит и outbox пишутся ОДНОЙ порцией
            // (flush ниже). Семантика append-only (FR-1.8) не меняется, запись
            // по-прежнему в той же транзакции, что и ставка.
            flush: false,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'auction.bid',
            payload: [
                'auction_id' => (string) $auction->getId(),
                'bid_id' => (string) $bid->getId(),
                'price_minor' => $priceMinor,
                'round' => $round,
                'is_first_price' => $isFirst,
                // B5: зафиксированная первой ставкой start_price_minor — база
                // для обеспечения заявки (% × первая_ставка, FR-1.4.1; модуль
                // securities).
                'start_price_minor' => $auction->getStartPriceMinor(),
            ],
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
        ));

        // Один flush на ставку (ставка + аудит + outbox) — меньше round trips
        // на запись, критерий 4.10 «100–200 ставок/сек» (NFR-1).
        // Счётчик принятых ставок (auction_bids_total) здесь НЕ инкрементится:
        // он должен учитывать только закоммиченные ставки — вызов после
        // коммита в AuctionBidService::transactionalBid (см. там).
        $this->em->flush();

        return $bid;
    }

    /**
     * Идемпотентность ставки (ARCH-6, FR-1.3.6): повторная доставка с тем же
     * Idempotency-Key (at-least-once через outbox/messenger или клиентский
     * retry) возвращает уже принятую ставку — дубль не создаётся. Проверка
     * выполняется под pessimistic lock строки аукциона, поэтому гонок нет;
     * уникальный индекс (auction_id, idempotency_key) — второй рубеж.
     */
    public function replayBid(Auction $auction, ?string $idempotencyKey): ?AuctionBid
    {
        if (null === $idempotencyKey || '' === $idempotencyKey) {
            return null;
        }

        return $this->auctions->findBidByAuctionAndIdempotencyKey($auction, $idempotencyKey);
    }

    /**
     * Pessimistic lock строки аукциона (SELECT ... FOR UPDATE) внутри активной
     * транзакции: сериализует read-modify-write ставки на один аукцион
     * (FR-1.3.6). Возвращает managed-сущность (если уже в UnitOfWork — тот же
     * объект, данные актуальны на момент блокировки).
     *
     * @throws AuctionNotFoundException если аукцион не найден
     */
    public function lockAuction(Uuid $auctionId): Auction
    {
        $query = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Auction::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $auctionId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE);

        /** @var Auction|null $auction */
        $auction = $query->getOneOrNullResult();
        if (null === $auction) {
            throw new AuctionNotFoundException();
        }

        return $auction;
    }

    /**
     * Номер следующего раунда: max(round) по принятым ставкам + 1. Раунд
     * увеличивается на каждую принятую ставку (глобально), unique
     * (auction_id, bidder_id, round) гарантирует «одну ставку на участника
     * на ход» (data-model.md 2.6).
     */
    public function nextRound(Auction $auction): int
    {
        $max = $this->em->createQueryBuilder()
            ->select('MAX(b.round)')
            ->from(AuctionBid::class, 'b')
            ->where('b.auction = :auction')
            ->setParameter('auction', $auction)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }

    /**
     * Наличие ставки/предложения участника в аукционе. Для PRICE_REQUEST
     * (M12, FR-1.3.2) — «одно ценовое предложение на участника на окно»:
     * повторная подача того же участника отклоняется (duplicate_bid).
     */
    public function hasBid(Auction $auction, Uuid $bidderId): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(AuctionBid::class, 'b')
            ->where('b.auction = :auction')
            ->andWhere('b.bidderId = :bidderId')
            ->setParameter('auction', $auction)
            ->setParameter('bidderId', $bidderId)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }

    /**
     * execution_start_at лота аукциона (антиснайпинг, FR-1.3.3): лот не
     * связан с аукционом объектом — грузится через публичный read-контракт
     * Tender-модуля (TenderReadService::resolveLot).
     */
    private function lotExecutionStartAt(Auction $auction): ?\DateTimeImmutable
    {
        $lot = $this->tenders->resolveLot($auction->getTenderId(), (string) $auction->getLotId());

        return $lot?->getExecutionStartAt();
    }
}
