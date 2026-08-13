<?php

declare(strict_types=1);

namespace App\Notification\Command;

use App\Notification\NotificationDigestMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Планирование ежедневного дайджеста уведомлений (FR-1.6).
 *
 * Отправляет первую NotificationDigestMessage с задержкой `notification_digest_interval`
 * (по умолчанию сутки) в Redis-транспорт; обработчик сам планирует следующий
 * запуск. Вызывается после деплоя (bootstrapping цикла рассылки).
 * Запуск: php bin/console notifications:digest:schedule.
 */
#[AsCommand(name: 'notifications:digest:schedule', description: 'Schedule the next daily notification digest run')]
final class NotificationDigestScheduleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly int $intervalSeconds,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->bus->dispatch(
            new NotificationDigestMessage(),
            [new DelayStamp($this->intervalSeconds * 1000)],
        );

        $io->success(\sprintf('Digest run scheduled in %d second(s)', $this->intervalSeconds));

        return Command::SUCCESS;
    }
}
