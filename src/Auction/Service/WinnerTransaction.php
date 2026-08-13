<?php

declare(strict_types=1);

namespace App\Auction\Service;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Exception\AuctionNotFoundException;
use App\Bid\BidResultService;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Tender\LotWriteService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Транзакционный «хвост» выбора победителя (внутренний support-класс модуля
 * Auction): фиксация победителя (auctions.winner_bid_id, lots.winner_bid_id),
 * статусы заявок (winning/lost), переход CHOICE → APPROVE (APPROVE_WINNER, T23),
 * аудит (PR-9), outbox auction.winner_chosen, tenant-проверка и pessimistic
 * lock строки аукциона — в одном месте. Вызывается из AuctionWinnerService
 * внутри активной транзакции (em->wrapInTransaction остаётся в сервисе, где
 * выполняется валидация статусов и режима выбора).
 *
 * Инварианты (domain/auction-state-machine.md, раздел 7): выбор победителя
 * выполняется под pessimistic lock строки аукциона (SELECT ... FOR UPDATE) —
 * конкурентная ставка дожидается коммита, двойной победитель невозможен
 * (после CHOICE ставки не принимаются). Redis-снапшот live-состояния
 * обновляется ПОСЛЕ коммита (AuctionStateService), не входит в транзакцию БД.
 */
final readonly class WinnerTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private BidResultService $bidResults,
        private LotWriteService $lots,
        #[Autowire(service: 'state_machine.auction')]
        private WorkflowInterface $auctionWorkflow,
    ) {
    }

    /**
     * Хвост выбора победителя (общий для авто/ручного): фиксация победителя
     * (auctions.winner_bid_id, lots.winner_bid_id), статусы заявок участников
     * (winning/lost), переход CHOICE → APPROVE (APPROVE_WINNER, T23), аудит
     * (PR-9) и outbox auction.winner_chosen. Вызывается внутри транзакции.
     */
    public function chooseWinner(
        Auction $auction,
        AuctionBid $winningBid,
        string $mode,
        \DateTimeImmutable $now,
        ?string $ip,
        ?User $actor = null,
    ): Auction {
        $auction->setWinnerBidId($winningBid->getId());
        $auction->setActualEndAt($now);
        $this->markBidResults($auction, $winningBid->getBidderId());
        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::APPROVE_WINNER->value);
        $this->em->flush();

        $this->audit->record(
            action: 'auction.winner_chosen',
            entityType: 'auction',
            entityId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
            actorType: null !== $actor ? 'user' : 'system',
            actorId: null !== $actor ? (string) $actor->getId() : null,
            before: ['status' => AuctionStatusEnum::CHOICE->value],
            after: [
                'status' => AuctionStatusEnum::APPROVE->value,
                'mode' => $mode,
                'winner_bid_id' => (string) $winningBid->getId(),
                'supplier_id' => (string) $winningBid->getBidderId(),
                'price_minor' => $winningBid->getPriceMinor(),
                'price_basis' => $auction->getPriceBasis()->value,
                'vat_rate_bps' => $auction->getVatRateBps(),
                'actual_end_at' => $now->format('Y-m-d\TH:i:s\Z'),
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'auction.winner_chosen',
            payload: [
                'auction_id' => (string) $auction->getId(),
                'supplier_id' => (string) $winningBid->getBidderId(),
                'price_minor' => $winningBid->getPriceMinor(),
                'basis' => $auction->getPriceBasis()->value,
                'vat_rate' => $auction->getVatRateBps(),
                'mode' => $mode,
            ],
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
        ));
        $this->em->flush();

        return $auction;
    }

    /**
     * Отметка итогов по заявкам (data-model.md, bids.status): победителю —
     * winning, остальным допущенным участникам лота — lost; лот фиксирует
     * winner_bid_id (bids.id заявки победителя). Мутации чужих заявок — через
     * write-контракт Bid-модуля (BidResultService), а не напрямую.
     */
    private function markBidResults(Auction $auction, Uuid $winnerSupplierId): void
    {
        $winningTenderBidId = $this->bidResults->markResults(
            $auction->getTenderId(),
            $auction->getLotId(),
            $winnerSupplierId,
        );

        if (null !== $winningTenderBidId) {
            $this->lots->setWinnerBidId($auction->getLotId(), $winningTenderBidId);
        }
    }

    /**
     * Tenant-изоляция (AGENTS.md): завершение/выбор победителя выполняет только
     * заказчик — компания-тенант аукциона (= customerId тендера). Чужой актор
     * получает 404 (AuctionNotFoundException), как в других сервисах (BidService).
     * Актёр = null (система/расписание) — проверка не выполняется.
     */
    public function assertCustomer(Auction $auction, ?User $actor): void
    {
        if (null === $actor) {
            return;
        }

        $companyId = $actor->getCompanyId();
        if (null === $companyId || !$auction->getTenantId()->equals($companyId)) {
            throw new AuctionNotFoundException();
        }
    }

    /**
     * Pessimistic lock строки аукциона (SELECT ... FOR UPDATE) внутри активной
     * транзакции: сериализует выбор победителя с конкурентными ставками
     * (FR-1.3.6, «конкурентные T16/T17 — без двойного победителя»).
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
}
