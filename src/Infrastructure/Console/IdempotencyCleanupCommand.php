<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Shared\Idempotency\IdempotencyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention idempotency_keys (AR-4, testing-strategy.md §6): удаляет
 * истёкшие по TTL ключи. Запуск: php bin/console idempotency:cleanup.
 */
#[AsCommand(name: 'idempotency:cleanup', description: 'Delete expired idempotency keys (TTL retention)')]
final class IdempotencyCleanupCommand extends Command
{
    public function __construct(private readonly IdempotencyService $service)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deleted = $this->service->deleteExpired();
        $io->success(\sprintf('Deleted %d expired idempotency key(s)', $deleted));

        return Command::SUCCESS;
    }
}
