<?php

declare(strict_types=1);

namespace App\Auction\Presenter;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionStatusEnum;

/**
 * Публичное представление аукциона (openapi schemas AuctionState / AuctionBid).
 *
 * GET /auctions/{id}/state — статус + правила (rules_snapshot) + таймер
 * (remaining_sec); источник истины — сущность (PostgreSQL), live-поля актуальны
 * на момент запроса (Redis-снапшот для этого endpoint не обязателен).
 *
 * GET /auctions/{id}/bids — история ставок. Анонимность (openapi AuctionBid.
 * bidder_id «анонимно до конца торгов»): пока аукцион принимает ставки (TRADE),
 * bidder_id маскируется (null); после окончания торгов идентичность раскрывается.
 * Ответ на собственную ставку (POST) всегда содержит bidder_id (это ставка
 * вызывающего участника).
 *
 * Деньги — только int minor units (PR-1); price_basis — каноническая база
 * сравнения (PR-6).
 */
final class AuctionPresenter
{
    /**
     * Состояние аукциона (openapi AuctionState).
     *
     * @return array<string, mixed>
     */
    public function state(Auction $auction): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'id' => (string) $auction->getId(),
            'tender_id' => (string) $auction->getTenderId(),
            'lot_id' => (string) $auction->getLotId(),
            'type' => $auction->getType()->value,
            'step_mode' => $auction->getStepMode()->value,
            'no_start_price' => $auction->isNoStartPrice(),
            'status' => $auction->getStatus()->value,
            'scheduled_start_at' => $auction->getScheduledStartAt()?->format('Y-m-d\TH:i:s\Z'),
            'trade_end_lead_hours' => $auction->getTradeEndLeadHours(),
            'price_basis' => $auction->getPriceBasis()->value,
            'vat_rate_bps' => $auction->getVatRateBps(),
            'start_price_minor' => $auction->getStartPriceMinor(),
            'current_price_minor' => $auction->getCurrentPriceMinor(),
            'bid_step_minor' => $auction->getBidStepMinor(),
            'bid_step_percent_bps' => $auction->getBidStepPercentBps(),
            'price_min_limit_minor' => $auction->getPriceMinLimitMinor(),
            'price_max_limit_minor' => $auction->getPriceMaxLimitMinor(),
            'step_duration_sec' => $auction->getStepDurationSec(),
            'max_extensions' => $auction->getMaxExtensions(),
            'extensions_count' => $auction->getExtensionsCount(),
            'planned_end_at' => $auction->getPlannedEndAt()?->format('Y-m-d\TH:i:s\Z'),
            'remaining_sec' => $this->remainingSec($auction, $now),
            'started_at' => $auction->getStartedAt()?->format('Y-m-d\TH:i:s\Z'),
            'paused_remaining_sec' => $auction->getPausedRemainingSec(),
            'winner_bid_id' => null !== $auction->getWinnerBidId()
                ? (string) $auction->getWinnerBidId()
                : null,
            'version' => $auction->getVersion(),
            'updated_at' => $auction->getUpdatedAt()->format('Y-m-d\TH:i:s\Z'),
            'rules_snapshot' => $auction->getRulesSnapshot(),
        ];
    }

    /**
     * Элемент списка аукционов компании (openapi AuctionListItem, GET /auctions).
     * Компактное представление для списка: id, тендер/лот, тип, статус, цены,
     * таймер. Полная детализация — GET /auctions/{id}/state (AuctionState).
     *
     * @return array<string, mixed>
     */
    public function listItem(Auction $auction): array
    {
        return [
            'id' => (string) $auction->getId(),
            'tender_id' => (string) $auction->getTenderId(),
            'lot_id' => (string) $auction->getLotId(),
            'type' => $auction->getType()->value,
            'status' => $auction->getStatus()->value,
            'no_start_price' => $auction->isNoStartPrice(),
            'current_price_minor' => $auction->getCurrentPriceMinor(),
            'start_price_minor' => $auction->getStartPriceMinor(),
            'planned_end_at' => $auction->getPlannedEndAt()?->format('Y-m-d\TH:i:s\Z'),
            'remaining_sec' => $this->remainingSec($auction, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
            'winner_bid_id' => null !== $auction->getWinnerBidId() ? (string) $auction->getWinnerBidId() : null,
        ];
    }

    /**
     * Ставка аукциона (openapi AuctionBid).
     *
     * @return array<string, mixed>
     */
    public function bid(AuctionBid $bid, bool $revealBidder = true): array
    {
        return [
            'id' => (string) $bid->getId(),
            'auction_id' => (string) $bid->getAuction()->getId(),
            'round' => $bid->getRound(),
            'price_minor' => $bid->getPriceMinor(),
            'price_display_minor' => $bid->getPriceDisplayMinor(),
            'price_basis' => $bid->getPriceBasis()->value,
            'bidder_id' => $revealBidder ? (string) $bid->getBidderId() : null,
            'is_first_price' => $bid->isFirstPrice(),
            'placed_at' => $bid->getPlacedAt()->format('Y-m-d\TH:i:s\Z'),
            'status' => $bid->getStatus()->value,
            'reason' => $bid->getReason(),
        ];
    }

    /**
     * История ставок аукциона (openapi GET /auctions/{id}/bids).
     *
     * @param list<AuctionBid> $bids
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: null}
     */
    /**
     * @param list<AuctionBid> $bids
     *
     * @return list<array<string, mixed>>
     */
    public function bidList(array $bids, bool $revealBidder): array
    {
        $items = [];
        foreach ($bids as $bid) {
            $items[] = $this->bid($bid, $revealBidder);
        }

        return $items;
    }

    /**
     * Остаток торгов в секундах (openapi AuctionState.remaining_sec):
     * при паузе — зафиксированный остаток paused_remaining_sec (таймер
     * заморожен, UC-15); иначе planned_end_at − now; без таймера — null.
     */
    private function remainingSec(Auction $auction, \DateTimeImmutable $now): ?int
    {
        if (AuctionStatusEnum::PAUSED === $auction->getStatus()) {
            return $auction->getPausedRemainingSec();
        }

        $end = $auction->getPlannedEndAt();
        if (null === $end) {
            return null;
        }

        return max(0, $end->getTimestamp() - $now->getTimestamp());
    }
}
