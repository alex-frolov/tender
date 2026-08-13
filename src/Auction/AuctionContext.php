<?php

declare(strict_types=1);

namespace App\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use Symfony\Component\Uid\Uuid;

/**
 * Read-модель аукциона для кросс-модульных потребителей (Contract: исполнение
 * договора, претензии). Value object — immutable, без связи с ORM.
 *
 * Публичный контракт модуля Auction (корневой namespace): другие модули
 * НЕ получают сущность App\Auction\Entity\Auction (границы модулей,
 * PHPArkitect rule 6) — только этот срез: identity + статус + привязки.
 * Значения соответствуют полям auctions (data-model.md 2.6).
 */
final readonly class AuctionContext
{
    public function __construct(
        public Uuid $id,
        public Uuid $tenantId,
        public Uuid $tenderId,
        public Uuid $lotId,
        public AuctionStatusEnum $status,
        public ?Uuid $winnerBidId,
    ) {
    }

    public static function fromEntity(Auction $auction): self
    {
        return new self(
            id: $auction->getId(),
            tenantId: $auction->getTenantId(),
            tenderId: $auction->getTenderId(),
            lotId: $auction->getLotId(),
            status: $auction->getStatus(),
            winnerBidId: $auction->getWinnerBidId(),
        );
    }
}
