<?php

declare(strict_types=1);

namespace App\Notification\Command;

use App\Notification\NotificationDigestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ручной запуск ежедневного дайджеста уведомлений (FR-1.6).
 *
 * Рассылает накопленные события (notification_digest_items) без планирования
 * следующего запуска — для оперативных/тестовых вызовов. Штатный цикл —
 * NotificationDigestMessageHandler (self-scheduling) после notifications:digest:schedule.
 * Запуск: php bin/console notifications:digest:send.
 */
#[AsCommand(name: 'notifications:digest:send', description: 'Send pending notification digests now')]
final class NotificationDigestSendCommand extends Command
{
    public function __construct(private readonly NotificationDigestService $digests)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sent = $this->digests->sendDigests();
        $io->success(\sprintf('Sent %d digest email(s)', $sent));

        return Command::SUCCESS;
    }
}
