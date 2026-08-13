<?php

declare(strict_types=1);

namespace App\Auction\Service;

use App\Auction\AuctionContext;
use App\Auction\AuctionLifecycleService as AuctionLifecycleServiceContract;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Repository\AuctionRepository;
use App\Auction\WinningBidResult;
use App\Bid\BidReadService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Shared\Exception\StateTransitionException;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Реализация публичного контракта жизненного цикла аукциона (см.
 * App\Auction\AuctionLifecycleService). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 *
 * Единственный кросс-модульный путь к state_machine.auction: сущность Auction
 * загружается ВНУТРИ модуля, потребитель (Contract) работает с Uuid +
 * AuctionContext и не трогает ни WorkflowInterface, ни App\Auction\Entity.
 * Победителя исполнителя разрешаем через публичные контракты Tender/Bid
 * (TenderReadService::resolveLot, BidReadService::findById) + auction_bids
 * (запасной путь) — логика переехала из ContractExecutionService (P2).
 */
final readonly class AuctionLifecycleService implements AuctionLifecycleServiceContract
{
    public function __construct(
        private AuctionRepository $auctions,
        private EntityManagerInterface $em,
        #[Autowire(service: 'state_machine.auction')]
        private WorkflowInterface $auctionWorkflow,
        private TenderReadService $tenders,
        private BidReadService $bids,
    ) {
    }

    public function findById(Uuid $auctionId): ?AuctionContext
    {
        $auction = $this->auctions->findById($auctionId->toRfc4122());
        if (null === $auction) {
            return null;
        }

        return AuctionContext::fromEntity($auction);
    }

    public function listForTender(Uuid $tenderId): array
    {
        /** @var list<AuctionContext> $contexts */
        $contexts = array_map(
            static fn (Auction $auction): AuctionContext => AuctionContext::fromEntity($auction),
            $this->auctions->listForTender($tenderId),
        );

        return $contexts;
    }

    public function applyTransition(Uuid $auctionId, AuctionStatusTransition $transition): AuctionContext
    {
        $auction = $this->requireAuction($auctionId);

        $name = $transition->value;
        if (!$this->auctionWorkflow->can($auction, $name)) {
            throw new StateTransitionException(\sprintf('Auction cannot apply transition %s from status %s', $name, $auction->getStatus()->value));
        }

        $this->auctionWorkflow->apply($auction, $name);
        $this->em->flush();

        return AuctionContext::fromEntity($auction);
    }

    public function winnerSupplierId(Uuid $auctionId): Uuid
    {
        $auction = $this->requireAuction($auctionId);

        // Лот фиксирует заявку победителя (bids.id) при выборе —
        // supplierId исполнителя берём из неё. Лот грузим через публичный
        // read-контракт Tender-модуля (TenderReadService::resolveLot).
        $lot = $this->tenders->resolveLot($auction->getTenderId(), (string) $auction->getLotId());
        $lotWinnerBidId = $lot?->getWinnerBidId();
        if (null !== $lotWinnerBidId) {
            // Победителя лота грузим через публичный read-контракт Bid-модуля
            // (BidReadService), а не напрямую через EM/чужой Repository.
            $winningTenderBid = $this->bids->findById($lotWinnerBidId);
            if (null !== $winningTenderBid) {
                return $winningTenderBid->getSupplierId();
            }
        }

        // Запасной путь: auction.winnerBidId — auction_bids.id победившей ставки.
        $winnerAuctionBidId = $auction->getWinnerBidId();
        if (null !== $winnerAuctionBidId) {
            $winningAuctionBid = $this->auctions->findWinningBid($auction);
            if (null !== $winningAuctionBid) {
                return $winningAuctionBid->getBidderId();
            }
        }

        throw new ConflictException('Auction winner is not resolved');
    }

    public function winningBidResult(Uuid $auctionId): ?WinningBidResult
    {
        $auction = $this->requireAuction($auctionId);
        $bid = $this->auctions->findWinningBid($auction);
        if (null === $bid) {
            return null;
        }

        return new WinningBidResult(
            bidderId: $bid->getBidderId(),
            priceMinor: $bid->getPriceMinor(),
        );
    }

    /**
     * @throws NotFoundException если аукцион не найден
     */
    private function requireAuction(Uuid $auctionId): Auction
    {
        $auction = $this->auctions->findById($auctionId->toRfc4122());
        if (null === $auction) {
            throw new NotFoundException('Auction not found');
        }

        return $auction;
    }
}
