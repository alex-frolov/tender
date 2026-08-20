<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Shared\Events\OutboxRelayer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Релизер outbox: публикует pending-события в RabbitMQ.
 *
 * Запуск: php bin/console outbox:relay [--limit=100] [--once] [--heartbeat=PATH]
 * - без --once — бесконечный цикл с паузой (для супервизора/контейнера worker);
 * - --once — один батч (для cron);
 * - --heartbeat — файл, mtime которого обновляется на каждой итерации цикла
 *   (healthcheck контейнера worker). Без него смерть релизера не видна снаружи:
 *   контейнер остаётся Up на messenger:consume, а события копятся в outbox
 *   в статусе pending — молча отваливаются live-события аукциона (Mercure),
 *   webhook-доставка и почта.
 */
#[AsCommand(name: 'outbox:relay', description: 'Relay pending outbox events to RabbitMQ')]
final class OutboxRelayCommand extends Command
{
    private const int DEFAULT_PAUSE_SECONDS = 1;

    public function __construct(private readonly OutboxRelayer $relayer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Batch size', '100')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Relay one batch and exit')
            ->addOption('pause', null, InputOption::VALUE_OPTIONAL, 'Pause between batches (sec)', (string) self::DEFAULT_PAUSE_SECONDS)
            ->addOption('heartbeat', null, InputOption::VALUE_REQUIRED, 'Touch this file on every loop iteration (container healthcheck)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $this->intOption($input, 'limit', 100);
        $pause = $this->intOption($input, 'pause', self::DEFAULT_PAUSE_SECONDS);
        $once = (bool) $input->getOption('once');
        $heartbeat = $input->getOption('heartbeat');
        $heartbeatPath = \is_string($heartbeat) && '' !== $heartbeat ? $heartbeat : null;

        while (true) {
            $this->touchHeartbeat($heartbeatPath);
            $sent = $this->relayer->relay($limit);
            if ($once) {
                $io->success(\sprintf('Relayed %d outbox event(s)', $sent));

                return Command::SUCCESS;
            }
            if ($sent > 0) {
                $io->writeln(\sprintf('<info>%d</info> event(s) relayed', $sent));
            }
            // при пустом outbox — пауза, чтобы не молотить БД
            if (0 === $sent) {
                usleep($pause * 1_000_000);
            }
        }
    }

    /**
     * Отметка «релизер жив» для healthcheck'а: пишется до каждой выборки
     * батча, поэтому устаревает и при падении процесса, и при его зависании.
     * Сбой записи не должен ронять релей — heartbeat вторичен по отношению
     * к доставке событий.
     */
    private function touchHeartbeat(?string $path): void
    {
        if (null === $path) {
            return;
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return;
        }

        @touch($path);
    }

    private function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);
        if (null === $value || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }
}
