<?php

declare(strict_types=1);

namespace App\Auction\Command;

use App\Auction\AuctionWinnerService;
use App\Auction\Repository\AuctionRepository;
use App\Shared\Exception\StateTransitionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Закрытие торгов с истёкшим окном (TRADE → CHOICE, T16, FR-1.3.3).
 *
 * Истечение planned_end_at само по себе торги не закрывало: переход выполнял
 * только заказчик (POST /auctions/{id}/finish или выбор победителя), а
 * планировщик знал лишь про старт торгов и heartbeat. В результате аукцион
 * с давно истёкшим таймером оставался в TRADE неограниченно долго — и при этом
 * считался живым (heartbeat его продлевал).
 *
 * Команда закрывает разрыв: берёт TRADE-аукционы с наступившим planned_end_at
 * и завершает торги от имени системы (actor = null, tenancy-проверка
 * пропускается — инициатор не пользователь). Победитель при этом НЕ выбирается:
 * это отдельное решение заказчика (для редукциона — автовыбор минимальной цены,
 * для остальных типов — ручной выбор предложения).
 *
 * Идемпотентна: уже завершённые аукционы в выборку не попадают, а гонка
 * с ручным завершением даёт StateTransitionException на одном аукционе и
 * не мешает остальным.
 *
 * Запуск: php bin/console auctions:finish-expired [--dry-run]
 */
#[AsCommand(name: 'auctions:finish-expired', description: 'Finish trading whose window has closed (TRADE → CHOICE, FR-1.3.3)')]
final class AuctionFinishExpiredCommand extends Command
{
    public function __construct(
        private readonly AuctionRepository $auctions,
        private readonly AuctionWinnerService $winners,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report expired auctions, do not finish them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expired = $this->auctions->listExpiredTrading($now);

        if ([] === $expired) {
            $io->success('No trading auctions with a closed window');

            return Command::SUCCESS;
        }

        if (true === $input->getOption('dry-run')) {
            foreach ($expired as $auction) {
                $io->writeln(\sprintf(
                    'expired: %s (planned end %s)',
                    (string) $auction->getId(),
                    $auction->getPlannedEndAt()?->format(\DATE_ATOM) ?? '—',
                ));
            }
            $io->success(\sprintf('%d auction(s) with a closed window', \count($expired)));

            return Command::SUCCESS;
        }

        $finished = 0;
        $failed = 0;
        foreach ($expired as $auction) {
            try {
                // actor = null: инициатор — система, tenancy-проверка не нужна.
                $this->winners->finish($auction, null, $now);
                ++$finished;
            } catch (StateTransitionException $e) {
                // Статус изменился между выборкой и завершением (заказчик успел
                // завершить сам или отменил аукцион) — остальные не трогаем.
                ++$failed;
                $io->warning(\sprintf('Auction %s not finished: %s', (string) $auction->getId(), $e->getMessage()));
            }
        }

        $io->success(\sprintf('Finished %d auction(s), skipped %d', $finished, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
