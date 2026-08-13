<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Shared\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dev-команда: создаёт тестовое outbox-событие (для проверки релизера).
 *
 * php bin/console outbox:test-event tender.published tenant-1 tender t-1 '{"title":"x"}'
 */
#[AsCommand(name: 'outbox:test-event', description: 'Create a test outbox event (dev)')]
final class OutboxTestEventCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('event-type', InputArgument::REQUIRED, 'Event type, e.g. tender.published')
            ->addArgument('aggregate-type', InputArgument::REQUIRED, 'Aggregate type')
            ->addArgument('aggregate-id', InputArgument::REQUIRED, 'Aggregate id')
            ->addArgument('tenant-id', InputArgument::OPTIONAL, 'Tenant id', null)
            ->addArgument('payload', InputArgument::OPTIONAL, 'JSON payload', '{}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $decoded = json_decode($this->stringArgument($input, 'payload'), true);
        if (!\is_array($decoded)) {
            $io->error('Payload must be valid JSON object');

            return Command::FAILURE;
        }
        /** @var array<string, mixed> $payload */
        $payload = $decoded;

        $tenantId = $input->getArgument('tenant-id');
        if (null !== $tenantId && !\is_string($tenantId)) {
            $io->error('Tenant id must be a string');

            return Command::FAILURE;
        }

        $event = new OutboxEvent(
            eventType: $this->stringArgument($input, 'event-type'),
            payload: $payload,
            aggregateType: $this->stringArgument($input, 'aggregate-type'),
            aggregateId: $this->stringArgument($input, 'aggregate-id'),
            tenantId: $tenantId,
        );
        $this->em->persist($event);
        $this->em->flush();

        $io->success(\sprintf('Outbox event #%d created (status pending)', $event->getId()));

        return Command::SUCCESS;
    }

    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('Argument "%s" must be a string', $name));
        }

        return $value;
    }
}
