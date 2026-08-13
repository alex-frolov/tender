<?php

declare(strict_types=1);

namespace App\Auction\Command;

use App\Auction\State\AuctionStateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Восстановление Redis-снапшотов live-состояния аукционов из источника
 * истины (PostgreSQL) после сбоя Redis (FR-1.3.6, UC-15). Пересоздаёт
 * снапшоты всех аукционов в TRADE. Запуск: php bin/console auctions:state:rebuild.
 */
#[AsCommand(name: 'auctions:state:rebuild', description: 'Rebuild Redis live-state snapshots for trading auctions from PostgreSQL (FR-1.3.6)')]
final class AuctionStateRebuildCommand extends Command
{
    public function __construct(private readonly AuctionStateService $state)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rebuilt = $this->state->rebuildAll();
        $io->success(\sprintf('Rebuilt live-state snapshot(s) for %d trading auction(s)', $rebuilt));

        return Command::SUCCESS;
    }
}
