<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\AuctionBidService;
use App\Auction\Entity\Auction;
use App\Auction\Input\PlaceAuctionBidInput;
use App\Auction\Presenter\AuctionPresenter;
use App\Contract\ContractAccessChecker;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\TenderReadService;

/**
 * Подача ставки в аукционе (FR-1.3.2/1.3.8, POST /auctions/{auctionId}/bids).
 *
 * Прикладной слой: оркестрация действия (актор → компания, доступ к закрытому
 * тендеру, обязательность цены) и презентация ответа. Механика ставки по типу
 * аукциона (шаг/понижение/границы, идемпотентность, pessimistic lock) —
 * в доменном AuctionBidService; валидация body — формой AuctionBidType
 * в контроллере.
 *
 * Закрытый тендер (contract_holders, FR-1.5.14) проверяется и здесь, а не
 * только при подаче заявки: договор может быть расторгнут или истечь уже
 * после допуска, и тогда допущенный участник торговался бы без права участия.
 * Проверка стоит до транзакции ставки — под pessimistic lock аукциона
 * сериализуются все участники, и лишний запрос к договорам там был бы
 * в горячем пути (NFR-22).
 */
final readonly class PlaceBidUseCase implements AuctionUseCase
{
    public function __construct(
        private AuctionBidService $bids,
        private AuctionPresenter $presenter,
        private TenderReadService $tenders,
        private ContractAccessChecker $contractAccess,
    ) {
    }

    /**
     * @param PlaceAuctionBidInput $input валидированный формой DTO (цена в
     *                                    канонической базе, PR-1)
     *
     * @return array<string, mixed> презентация ставки (openapi AuctionBid)
     */
    public function execute(
        Auction $auction,
        User $user,
        PlaceAuctionBidInput $input,
        ?string $idempotencyKey = null,
        ?string $ip = null,
    ): array {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        $priceMinor = $input->priceMinor;
        if (null === $priceMinor) {
            throw new ValidationException('price_minor is required');
        }

        $tender = $this->tenders->resolveTender((string) $auction->getTenderId());
        if (AccessTypeEnum::CONTRACT_HOLDERS === $tender->getAccessType()) {
            $this->contractAccess->assertCanParticipate($tender->getCustomerId(), $companyId);
        }

        $bid = $this->bids->placeBid(
            auction: $auction,
            bidderId: $companyId,
            priceMinor: $priceMinor,
            idempotencyKey: $idempotencyKey,
            ip: $ip,
        );

        return $this->presenter->bid($bid);
    }
}
