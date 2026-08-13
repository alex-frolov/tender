<?php

declare(strict_types=1);

namespace App\Auction\State;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Repository\AuctionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Redis-снапшот live-состояния аукциона (ARCH-4, FR-1.3.6, UC-15).
 *
 * Источник истины — PostgreSQL (auctions). Redis-снапшот — query-path кэш
 * для быстрого чтения live-состояния (текущая цена, таймер, статус) без БД
 * и для публикации в SSE (Mercure): каждый запрос не трогает БД.
 *
 * Ключ: auction:state:{id}. Значение — JSON AuctionStateSnapshot (toArray).
 * TTL освежается при каждой записи; после сбоя Redis снапшоты
 * восстанавливаются из источника истины (rebuild / rebuildAll, UC-15).
 *
 * Запись выполняется ПОСЛЕ коммита транзакции ставки (AuctionBidService):
 * снапшот отражает зафиксированное состояние, атомарность ставки не
 * нарушается (FR-1.3.6: никакая ставка не теряется — источник истины PG).
 */
final class AuctionStateService
{
    private const string KEY_PREFIX = 'auction:state:';
    private const string HEARTBEAT_KEY_PREFIX = 'auction:heartbeat:';
    private const int TTL_SECONDS = 86400; // сутки; освежается при каждой записи

    public function __construct(
        private readonly \Redis $redis,
        private readonly AuctionRepository $auctions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Запись снапшота в Redis (после коммита транзакции ставки/старта).
     * Ошибка Redis не блокирует доменную операцию — лог и продолжение
     * (источник истины остаётся PostgreSQL; снапшот восстановим через rebuild).
     */
    public function write(Auction $auction, ?AuctionBid $lastBid = null): void
    {
        $snapshot = AuctionStateSnapshot::fromEntity($auction, $lastBid);

        try {
            $this->redis->setex(
                $this->key($auction->getId()),
                self::TTL_SECONDS,
                json_encode($snapshot->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            );
        } catch (\RedisException|\JsonException $e) {
            $this->logger->warning('Auction state snapshot write failed', [
                'auction_id' => (string) $auction->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Чтение снапшота из Redis (query path). null — нет снапшота в кэше.
     * При недоступности Redis возвращает null (read-путь деградирует на БД),
     * не бросает исключение.
     */
    public function read(Uuid $auctionId): ?AuctionStateSnapshot
    {
        try {
            $raw = $this->redis->get($this->key($auctionId));
        } catch (\RedisException $e) {
            $this->logger->warning('Auction state snapshot read failed', [
                'auction_id' => (string) $auctionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!\is_string($raw)) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        try {
            return AuctionStateSnapshot::fromArray($data);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Удаление снапшота (терминальное состояние / удаление аукциона).
     */
    public function delete(Uuid $auctionId): void
    {
        try {
            $this->redis->del($this->key($auctionId));
        } catch (\RedisException $e) {
            $this->logger->warning('Auction state snapshot delete failed', [
                'auction_id' => (string) $auctionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Восстановление снапшота из источника истины (PostgreSQL, UC-15):
     * перезаписывает кэш по текущему состоянию сущности.
     */
    public function rebuild(Auction $auction): void
    {
        $this->write($auction);
    }

    /**
     * Восстановление снапшотов всех живых аукционов (TRADE) из PostgreSQL
     * после сбоя Redis (FR-1.3.6, UC-15). Возвращает число восстановленных.
     */
    public function rebuildAll(): int
    {
        $count = 0;
        foreach ($this->auctions->listTrading() as $auction) {
            $this->rebuild($auction);
            ++$count;
        }

        return $count;
    }

    /**
     * Heartbeat аукциона (UC-15, FR-1.3.6): периодическая запись
     * «система жива» в Redis для TRADE-аукционов. Если heartbeat пропал
     * (сбой Redis / остановка сервиса) дольше порога AUCTION_HEARTBEAT_TIMEOUT,
     * аукцион авто-ставится на паузу (AuctionService::autoPauseStale) — таймер
     * заморожен, ставки не принимаются; после восстановления → resume.
     *
     * Heartbeat — отдельный ключ (не снапшот): снапшот пишется только при
     * изменениях (старт/ставка), а heartbeat — периодически, независимо от
     * активности участников.
     */
    public function heartbeat(Uuid $auctionId, ?\DateTimeImmutable $now = null, ?int $ttlSeconds = null): void
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ttlSeconds ??= self::TTL_SECONDS;

        try {
            $this->redis->setex(
                $this->heartbeatKey($auctionId),
                $ttlSeconds,
                $now->format('Y-m-d\TH:i:s\Z'),
            );
        } catch (\RedisException $e) {
            $this->logger->warning('Auction heartbeat write failed', [
                'auction_id' => (string) $auctionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Время последнего heartbeat аукциона (или null, если heartbeat отсутствует/
     * Redis недоступен). Используется для авто-паузы по простою > порога
     * (UC-15): аукцион без heartbeat дольше порога переводится в PAUSED.
     */
    public function lastHeartbeatAt(Uuid $auctionId): ?\DateTimeImmutable
    {
        try {
            $raw = $this->redis->get($this->heartbeatKey($auctionId));
        } catch (\RedisException $e) {
            $this->logger->warning('Auction heartbeat read failed', [
                'auction_id' => (string) $auctionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Простой аукциона (сек) с последнего heartbeat: now − last_heartbeat.
     * null — heartbeat отсутствует (аукцион «молчит» дольше, чем позволяет Redis
     * или ключ не создавался) → простой считается превышающим порог.
     */
    public function idleSeconds(Uuid $auctionId, ?\DateTimeImmutable $now = null): ?int
    {
        $last = $this->lastHeartbeatAt($auctionId);
        if (null === $last) {
            return null;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return max(0, $now->getTimestamp() - $last->getTimestamp());
    }

    private function key(Uuid $auctionId): string
    {
        return self::KEY_PREFIX.$auctionId;
    }

    private function heartbeatKey(Uuid $auctionId): string
    {
        return self::HEARTBEAT_KEY_PREFIX.$auctionId;
    }
}
