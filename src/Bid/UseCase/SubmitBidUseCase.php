<?php

declare(strict_types=1);

namespace App\Bid\UseCase;

use App\Bid\BidPresenter;
use App\Bid\BidService;
use App\Bid\Input\CreateBidInput;
use App\Iam\Entity\User;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\TenderReadService;
use Symfony\Component\Uid\Uuid;

/**
 * Подача/замена заявки (FR-1.2.1/1.2.5, POST /tenders/{tenderId}/bids).
 *
 * Тендер резолвится публичным TenderReadService (кросс-модульный контракт),
 * содержимое шифруется до вскрытия (FR-1.2.2), повторная подача на лот —
 * замена. Механика (tenant, допуск, шифрование) — в доменном BidService::submit;
 * валидация body — форма BidCreateType. Ответ — BidPresenter::metadata.
 * Доступ — право bids.submit через BidVoter.
 */
final readonly class SubmitBidUseCase implements BidUseCase
{
    public function __construct(
        private BidService $bids,
        private TenderReadService $tenders,
        private BidPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация заявки (openapi Bid, FR-1.2.2)
     */
    public function execute(User $user, string $tenderId, CreateBidInput $input, ?string $ip = null): array
    {
        $tender = $this->tenders->resolveTender($tenderId);

        $declaredSupplierId = Uuid::fromString($input->supplierId);

        $bid = $this->bids->submit(
            actor: $user,
            tender: $tender,
            lotId: $input->lotId,
            part1: $input->part1,
            part2Ref: $input->part2DocumentIds,
            priceMinor: $input->priceMinor,
            priceBasis: null !== $input->priceBasis ? PriceBasisEnum::from($input->priceBasis) : null,
            vatRate: $input->vatRate,
            ip: $ip,
            declaredSupplierId: $declaredSupplierId,
        );

        return $this->presenter->metadata($bid);
    }
}
