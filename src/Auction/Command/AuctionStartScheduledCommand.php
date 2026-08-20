<?php

declare(strict_types=1);

namespace App\Auction\Command;

use App\Auction\AuctionService;
use App\Auction\Repository\AuctionRepository;
use App\Shared\Exception\StateTransitionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Старт запланированных торгов (FR-1.3.1, T13: SCHEDULED → TRADE).
 *
 * Аукцион, которому задали scheduled_start_at (POST /auctions/{id}/schedule
 * или scheduled_start_at при создании), ждёт своего момента в статусе
 * SCHEDULED. Этот переход некому было выполнить: startTrading вызывался только
 * из консольной подготовки нагрузочного теста, поэтому назначенные торги
 * не начинались никогда. Команда закрывает разрыв — запускать по расписанию
 * (docker/scheduler-entrypoint.sh), интервал определяет точность старта.
 *
 * Идемпотентна: берутся только SCHEDULED-аукционы с наступившим временем;
 * уже стартовавшие в выборку не попадают. Сбой одного аукциона не останавливает
 * остальные (ошибка логируется в вывод, код возврата — FAILURE).
 *
 * Запуск: php bin/console auctions:start-scheduled [--dry-run]
 */
#[AsCommand(name: 'auctions:start-scheduled', description: 'Start scheduled auctions whose start time has come (SCHEDULED → TRADE, FR-1.3.1)')]
final class AuctionStartScheduledCommand extends Command
{
    public function __construct(
        private readonly AuctionRepository $auctions,
        private readonly AuctionService $auctionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report auctions due to start, do not start them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $due = $this->auctions->listDueForTrading($now);

        if ([] === $due) {
            $io->success('No scheduled auctions are due to start');

            return Command::SUCCESS;
        }

        if (true === $input->getOption('dry-run')) {
            foreach ($due as $auction) {
                $io->writeln(\sprintf(
                    'due: %s (scheduled at %s)',
                    (string) $auction->getId(),
                    $auction->getScheduledStartAt()?->format(\DATE_ATOM) ?? '—',
                ));
            }
            $io->success(\sprintf('%d auction(s) due to start', \count($due)));

            return Command::SUCCESS;
        }

        $started = 0;
        $failed = 0;
        foreach ($due as $auction) {
            try {
                $this->auctionService->startTrading($auction, $now);
                ++$started;
            } catch (StateTransitionException $e) {
                // Статус изменился между выборкой и стартом (гонка с отменой/
                // ручным стартом) — не повод ронять остальные аукционы.
                ++$failed;
                $io->warning(\sprintf('Auction %s not started: %s', (string) $auction->getId(), $e->getMessage()));
            }
        }

        $io->success(\sprintf('Started %d auction(s), skipped %d', $started, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
