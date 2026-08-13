<?php

declare(strict_types=1);

namespace App\Auction\Command;

use App\Auction\AuctionService;
use App\Auction\State\AuctionStateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Восстановление после сбоя (UC-15, FR-1.3.6):
 * 1. Восстановление Redis-снапшотов live-состояния из PostgreSQL (источник
 *    истины) — rebuildAll;
 * 2. Авто-пауза TRADE-аукционов, чей heartbeat в Redis пропал/простоил дольше
 *    порога AUCTION_HEARTBEAT_TIMEOUT (autoPauseStale) — таймер заморожен,
 *    остаток сохранён в БД, ставки целы;
 * 3. Дальнейшее возобновление — администратором через resume (или командой).
 *
 * Сценарий: «убийство Redis → рестарт → TRADE → авто-PAUSED (простой > порога)
 * → resume → ставки целы» (domain/auction-state-machine.md, раздел 7).
 *
 * Запуск: php bin/console auctions:recover [--heartbeat-timeout=300]
 */
#[AsCommand(name: 'auctions:recover', description: 'Recover auction live-state after Redis/RabbitMQ failure (UC-15, FR-1.3.6)')]
final class AuctionRecoverCommand extends Command
{
    public function __construct(
        private readonly AuctionStateService $state,
        private readonly AuctionService $auctions,
        private readonly int $heartbeatTimeoutSec,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('heartbeat-timeout', null, InputOption::VALUE_OPTIONAL, 'Heartbeat idle threshold (sec)', (string) $this->heartbeatTimeoutSec);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $timeout = max(1, $this->intOption($input, 'heartbeat-timeout', $this->heartbeatTimeoutSec));

        // 1. Источник истины (PostgreSQL) → Redis-снапшоты live-состояния.
        $rebuilt = $this->state->rebuildAll();

        // 2. Авто-пауза «молчащих» TRADE-аукционов (простой > порога heartbeat).
        $paused = $this->auctions->autoPauseStale($timeout);

        $io->success(\sprintf(
            'Recovery done: %d snapshot(s) rebuilt, %d auction(s) auto-paused',
            $rebuilt,
            $paused,
        ));

        return Command::SUCCESS;
    }

    private function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);
        if (!is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }
}
