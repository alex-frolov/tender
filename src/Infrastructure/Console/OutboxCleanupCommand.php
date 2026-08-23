<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Shared\Repository\OutboxEventRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention outbox_events: удаляет
 * опубликованные события старше OUTBOX_RETENTION_DAYS. Запуск:
 * php bin/console outbox:cleanup [--days=N] [--dry-run].
 *
 * По образцу idempotency:cleanup — периодический запуск из планировщика
 * (docker/scheduler-entrypoint.sh, OUTBOX_CLEANUP_EVERY_SEC). Без неё таблица
 * росла бесконечно: доставленное событие никем не читается, но остаётся
 * навсегда.
 *
 * Идемпотентна: повторный запуск удаляет только то, что попало за границу
 * retention с прошлого раза. pending-события не трогаются никогда — это
 * недоставленное (ARCH-3/NFR-5).
 */
#[AsCommand(name: 'outbox:cleanup', description: 'Delete published outbox events older than the retention window')]
final class OutboxCleanupCommand extends Command
{
    public function __construct(
        private readonly OutboxEventRepository $repository,
        private readonly ClockInterface $clock,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Retention window in days (defaults to OUTBOX_RETENTION_DAYS)',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report the retention boundary without deleting anything',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // getOption() отдаёт mixed: опция задана строкой из argv либо отсутствует.
        // Не-число осознанно превращается в 0 — отсекается проверкой ниже как
        // явная ошибка ввода, а не молчаливый откат к дефолту.
        $daysOption = $input->getOption('days');
        $days = match (true) {
            null === $daysOption => $this->retentionDays,
            is_numeric($daysOption) => (int) $daysOption,
            default => 0,
        };
        if ($days < 1) {
            $io->error('Retention window must be at least 1 day');

            return Command::INVALID;
        }

        $before = $this->clock->now()->modify(\sprintf('-%d days', $days));

        if (true === $input->getOption('dry-run')) {
            $io->info(\sprintf(
                'Dry run: would delete published outbox events created before %s (%d day(s))',
                $before->format(\DateTimeInterface::ATOM),
                $days,
            ));

            return Command::SUCCESS;
        }

        $deleted = $this->repository->deletePublishedOlderThan($before);
        $io->success(\sprintf(
            'Deleted %d published outbox event(s) older than %d day(s)',
            $deleted,
            $days,
        ));

        return Command::SUCCESS;
    }
}
