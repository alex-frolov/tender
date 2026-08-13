<?php

declare(strict_types=1);

namespace App\Auction\Stream;

use App\Auction\State\AuctionStateService;
use App\Auction\State\AuctionStateSnapshot;
use App\Shared\Events\EventMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * Публикация live-событий аукциона в Mercure hub (FR-1.3.4, ADR-003).
 *
 * Поток публикации — «из ядра» (ADR-003): outbox → RabbitMQ → консьюмер
 * (EventMessageHandler) → HTTP POST в hub (JWT publish). Данные события —
 * Redis-снапшот live-состояния (AuctionStateService), который уже содержит
 * последнюю ставку и таймер (AuctionStateSnapshot.lastBid*) — БД не читается.
 *
 * Типы SSE-событий (openapi /auctions/{id}/stream):
 *   state  — снапшот при подключении (отдаёт discovery, не публикуется ядром);
 *   bid    — новая ставка (auction.bid);
 *   status — смена статуса (auction.started/…);
 *   timer  — обновление таймера (приходит в составе снапшота bid).
 */
final class AuctionStreamPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly AuctionStateService $state,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Публикация события аукциона из консьюмера outbox (EventMessageHandler).
     * Обрабатывает только события auction.*; не-аукционные события игнорируются.
     */
    public function publishFromEvent(EventMessage $message): void
    {
        if (!str_starts_with($message->eventType, 'auction.')) {
            return;
        }

        $auctionId = $message->payload['auction_id'] ?? null;
        if (!\is_string($auctionId) || !Uuid::isValid($auctionId)) {
            return;
        }

        // Данные — Redis-снапшот live-состояния (без чтения БД, ARCH-4):
        // снапшот пишется после коммита ставки (AuctionBidService/AuctionService).
        $snapshot = $this->state->read(Uuid::fromString($auctionId));
        if (null === $snapshot) {
            // Снапшота нет (Redis недоступен / аукцион не в live) — нечего
            // транслировать; клиент восстановит состояние через discovery/state.
            return;
        }

        $this->publish($snapshot, self::sseType($message->eventType));
    }

    /**
     * Публикация снапшота на приватный topic `auction:{id}` (private update).
     * Сбой публикации (hub недоступен) не бросает исключение — SSE best-effort,
     * клиент переподключается и получает state через discovery.
     */
    public function publish(AuctionStateSnapshot $snapshot, string $type): void
    {
        try {
            $this->hub->publish(new Update(
                topics: [AuctionTopic::for($snapshot->auctionId)],
                data: json_encode($snapshot->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                private: true,
                type: $type,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed', [
                'auction_id' => $snapshot->auctionId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Маппинг доменного события → тип SSE-события.
     */
    private static function sseType(string $eventType): string
    {
        return match ($eventType) {
            'auction.bid' => 'bid',
            default => 'status',
        };
    }
}
