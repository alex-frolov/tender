<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Entity\Auction;
use App\Auction\Stream\AuctionStreamDiscovery;

/**
 * Discovery SSE-стрима аукциона (FR-1.3.4, ADR-003).
 *
 * Query-use-case: JWT-ссылка discovery на Mercure hub для приватного topic
 * `auction:{id}` (subscribe-JWT). Механика (токены, снапшот live-состояния) —
 * в AuctionStreamDiscovery.
 */
final readonly class GetAuctionStreamUseCase implements AuctionUseCase
{
    public function __construct(private AuctionStreamDiscovery $discovery)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Auction $auction): array
    {
        return $this->discovery->discover($auction);
    }
}
