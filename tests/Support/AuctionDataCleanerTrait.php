<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Гарантированная чистка данных аукциона для тестов с реальными COMMIT.
 *
 * Тесты с #[SkipDatabaseRollback] (AuctionBidLoadSmokeTest, AuctionBidLockTest)
 * работают вне dama-транзакции: созданные строки попадают в test-БД навсегда,
 * если не удалить их вручную. Чистка выполняется в tearDown() (вызывается и при
 * падении assert), а не в конце тела теста — проваленный тест не оставляет
 * следов в текущем прогоне.
 *
 * Последний рубеж — `composer test:prepare` (сброс test-БД drop+create+migrate
 * перед каждым полным прогоном): мусор от прерванного прогона (kill/таймаут)
 * стирается автоматически при следующем запуске.
 *
 * Redis-снапшоты (auction:state:* / auction:heartbeat:*) чистятся точечно по id —
 * глобальный FLUSHALL небезопасен (тестовый Redis общий с dev-стеком).
 */
trait AuctionDataCleanerTrait
{
    /** @var list<string> */
    private array $auctionIds = [];

    /** @var list<string> */
    private array $tenderIds = [];

    /** @var list<string> */
    private array $lotIds = [];

    /**
     * Регистрирует созданные тестом агрегаты для очистки в tearDown().
     */
    protected function trackAuctionData(string $auctionId, string $tenderId, string $lotId): void
    {
        $this->auctionIds[] = $auctionId;
        $this->tenderIds[] = $tenderId;
        $this->lotIds[] = $lotId;
    }

    protected function tearDown(): void
    {
        $this->cleanupAuctionData();
        parent::tearDown();
    }

    private function cleanupAuctionData(): void
    {
        if ([] === $this->auctionIds && [] === $this->tenderIds && [] === $this->lotIds) {
            return;
        }

        try {
            $container = self::getContainer();
            $em = $container->get(EntityManagerInterface::class);
            if (!$em instanceof EntityManagerInterface) {
                return;
            }
            $conn = $em->getConnection();

            foreach ($this->auctionIds as $id) {
                $conn->executeStatement('DELETE FROM auction_bids WHERE auction_id = :id', ['id' => $id]);
                $conn->executeStatement(
                    "DELETE FROM audit_log WHERE entity_type = 'auction' AND entity_id = :id",
                    ['id' => $id],
                );
                $conn->executeStatement(
                    "DELETE FROM outbox_events WHERE aggregate_type = 'auction' AND aggregate_id = :id",
                    ['id' => $id],
                );
                $conn->executeStatement('DELETE FROM auctions WHERE id = :id', ['id' => $id]);
            }

            // bids ссылаются и на tender_id, и на lot_id (FK) — удаляем до lots/tenders.
            foreach (array_unique([...$this->tenderIds, ...$this->lotIds]) as $id) {
                $conn->executeStatement('DELETE FROM bids WHERE tender_id = :id OR lot_id = :id', ['id' => $id]);
            }
            foreach ($this->tenderIds as $id) {
                $conn->executeStatement(
                    "DELETE FROM audit_log WHERE entity_type = 'tender' AND entity_id = :id",
                    ['id' => $id],
                );
                $conn->executeStatement(
                    "DELETE FROM outbox_events WHERE aggregate_type = 'tender' AND aggregate_id = :id",
                    ['id' => $id],
                );
            }
            // lots.tender_id → tenders: удаляем lots ДО tenders (и bids уже удалены выше).
            foreach ($this->lotIds as $id) {
                $conn->executeStatement('DELETE FROM lots WHERE id = :id', ['id' => $id]);
            }
            foreach ($this->tenderIds as $id) {
                $conn->executeStatement('DELETE FROM tenders WHERE id = :id', ['id' => $id]);
            }

            if ($container->has(\Redis::class)) {
                $redis = $container->get(\Redis::class);
                if ($redis instanceof \Redis) {
                    foreach ($this->auctionIds as $id) {
                        $redis->del('auction:state:'.$id, 'auction:heartbeat:'.$id);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Best-effort: авторитетная очистка — сброс test-БД в composer test:prepare.
            fwrite(\STDERR, \sprintf("\n  Auction data cleanup skipped: %s\n", $e->getMessage()));
        }
    }
}
