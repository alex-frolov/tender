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
 * Heartbeat live-аукционов (UC-15, FR-1.3.6): периодический
 * «система жива» для TRADE-аукционов в Redis. Запускать по расписанию
 * (cron/worker), интервал < AUCTION_HEARTBEAT_TIMEOUT, чтобы аукционы
 * не уходили в авто-паузу (autoPauseStale) при нормальной работе.
 *
 * Запуск: php bin/console auctions:heartbeat [--timeout=300]
 */
#[AsCommand(name: 'auctions:heartbeat', description: 'Refresh Redis heartbeat for trading auctions (UC-15, FR-1.3.6)')]
final class AuctionHeartbeatCommand extends Command
{
    public function __construct(
        private readonly AuctionStateService $state,
        private readonly AuctionService $auctions,
        private readonly int $heartbeatTtlSeconds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Heartbeat key TTL (sec)', (string) $this->heartbeatTtlSeconds);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ttl = max(1, $this->intOption($input, 'timeout', $this->heartbeatTtlSeconds));

        // Heartbeat для всех TRADE-аукционов (не трогаем снапшоты/ставки).
        $count = 0;
        foreach ($this->auctions->auctionsInTrade() as $auction) {
            $this->state->heartbeat($auction->getId(), ttlSeconds: $ttl);
            ++$count;
        }

        $io->success(\sprintf('Heartbeat refreshed for %d trading auction(s)', $count));

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
