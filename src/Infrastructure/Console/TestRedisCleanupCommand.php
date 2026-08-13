<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Очистка Redis-ключей, оставшихся от тестовых прогонов.
 *
 * Тесты пишут live-снапшоты (auction:state:* / auction:heartbeat:*) и
 * счётчики аналитики (ctr:*) в Redis, а dama-rollback БД их не
 * откатывает — за прогон накапливаются «мёртвые» ключи.
 * - auction:* — чистый query-path кэш (AuctionStateService, ARCH-4): источник
 *   истины PostgreSQL, при отсутствии ключа read-путь деградирует на БД,
 *   снапшоты восстановимы через AuctionStateService::rebuildAll (UC-15);
 * - ctr:* — Redis-счётчики аналитики (CounterService, ARCH-9): дельта между
 *   снапшотами, после сброса test-БД не имеют смысла (восстанавливаются из
 *   событий).
 *
 * Вызывается в `composer test:prepare` ПОСЛЕ сброса test-БД.
 */
#[AsCommand(name: 'app:test:redis-cleanup')]
final class TestRedisCleanupCommand extends Command
{
    public function __construct(private readonly \Redis $redis)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Удаляет Redis-снапшоты аукционов и счётчики аналитики, оставшиеся от тестовых прогонов');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->removeByPattern('auction:*') + $this->removeByPattern('ctr:*');
        $output->writeln(\sprintf('Redis test keys removed: %d', $removed));

        return Command::SUCCESS;
    }

    private function removeByPattern(string $pattern): int
    {
        $keys = $this->redis->keys($pattern);
        if (false === $keys || [] === $keys) {
            return 0;
        }

        return $this->redis->del($keys);
    }
}
