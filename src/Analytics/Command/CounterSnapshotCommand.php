<?php

declare(strict_types=1);

namespace App\Analytics\Command;

use App\Analytics\CounterSnapshotService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Снапшот Redis-счётчиков → analytics_counters (ARCH-9).
 *
 * Снимает текущую дельту счётчиков (ctr:*), накапливает в PG (upsert) и
 * ротирует Redis-ключи. Периодичность — COUNTER_SNAPSHOT_INTERVAL (внешний
 * крон/планировщик, например 300 сек). Идемпотентен: повторный запуск при
 * пустом Redis — no-op.
 *
 * Запуск: php bin/console analytics:counters:snapshot.
 */
#[AsCommand(name: 'analytics:counters:snapshot', description: 'Snapshot Redis analytics counters into analytics_counters (ARCH-9)')]
final class CounterSnapshotCommand extends Command
{
    public function __construct(private readonly CounterSnapshotService $service)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stats = $this->service->snapshot();

        $io->success(\sprintf(
            'Analytics counters snapshotted: %d counter(s), %d key(s) rotated',
            $stats['counters'],
            $stats['rotated'],
        ));

        return Command::SUCCESS;
    }
}
