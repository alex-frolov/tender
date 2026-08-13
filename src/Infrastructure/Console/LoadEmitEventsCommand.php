<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Генератор событий для webhook-нагрузки (NFR-3/WH-2..6):
 * вставляет N outbox-событий auction.bid одним батчем (raw SQL, минуя ORM),
 * дальше pipeline работает штатно: outbox → relayer → RabbitMQ →
 * EventMessageHandler → webhook-доставка.
 *
 *   php bin/console app:load:emit-events --auction=<id> --tenant=<id> --count=2000
 *
 * Payload — валидная схема auction.bid (config/schemas/events/auction.bid.json):
 * auction_id, bid_id, price_minor (убывает по шагу), round (нарастает).
 */
#[AsCommand(name: 'app:load:emit-events', description: 'Bulk-insert auction.bid outbox events for webhook load (task 7.2)')]
final class LoadEmitEventsCommand extends Command
{
    private const string STEP_MINOR = '50000';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('auction', null, InputOption::VALUE_REQUIRED, 'Auction id (aggregate_id)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant id')
            ->addOption('count', null, InputOption::VALUE_OPTIONAL, 'Number of events (one-shot)', '2000')
            ->addOption('price', null, InputOption::VALUE_OPTIONAL, 'Start price minor', '100000000')
            ->addOption('round', null, InputOption::VALUE_OPTIONAL, 'First round number', '1')
            ->addOption('rate', null, InputOption::VALUE_OPTIONAL, 'Events per minute (daemon mode)', '0')
            ->addOption('duration', null, InputOption::VALUE_OPTIONAL, 'Daemon duration in seconds', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $auctionId = $this->stringOption($input, 'auction');
        $tenantId = $this->stringOption($input, 'tenant');
        $startPrice = (int) $this->stringOption($input, 'price');
        $firstRound = (int) $this->stringOption($input, 'round');

        $rate = (int) $this->stringOption($input, 'rate');
        $duration = (int) $this->stringOption($input, 'duration');
        if ($rate > 0 && $duration > 0) {
            return $this->daemon($auctionId, $tenantId, $startPrice, $firstRound, $rate, $duration, $io);
        }

        $count = (int) $this->stringOption($input, 'count');
        if ($count <= 0) {
            $io->error('count must be > 0 (or use --rate/--duration daemon mode)');

            return Command::FAILURE;
        }

        $this->insertBatch($auctionId, $tenantId, $count, $startPrice, $firstRound);
        $io->success(\sprintf('Inserted %d auction.bid outbox events for auction %s', $count, $auctionId));

        return Command::SUCCESS;
    }

    /**
     * Daemon-режим для steady-state webhook-нагрузки (NFR-3): эмитит события
     * равномерно (rate/мин) в течение duration секунд, батчами по одному
     * событию в секунду — очереди webhook не переполняются, задержка доставки
     * остаётся в штатном режиме (< 5 сек, а не «хвост большого бурста»).
     */
    private function daemon(
        string $auctionId,
        string $tenantId,
        int $startPrice,
        int $firstRound,
        int $rate,
        int $duration,
        SymfonyStyle $io,
    ): int {
        $batch = (int) ceil($rate / 60);
        $elapsed = 0;
        $total = 0;
        while ($elapsed < $duration) {
            $this->insertBatch($auctionId, $tenantId, $batch, $startPrice, $firstRound + $total);
            $total += $batch;
            ++$elapsed;
            if ($elapsed >= $duration) {
                break;
            }
            sleep(1);
        }
        $io->success(\sprintf('Emitted %d auction.bid events (%d/min over %ds)', $total, $rate, $duration));

        return Command::SUCCESS;
    }

    private function insertBatch(string $auctionId, string $tenantId, int $count, int $startPrice, int $firstRound): void
    {
        $conn = $this->connection;
        $conn->executeStatement(
            'INSERT INTO outbox_events (event_type, payload, aggregate_type, aggregate_id, tenant_id, created_at)
             SELECT \'auction.bid\',
                    jsonb_build_object(
                        \'auction_id\', :auction::text,
                        \'bid_id\', gen_random_uuid(),
                        \'price_minor\', :price::bigint - ((i - 1) * :step::bigint),
                        \'round\', :firstRound::int + (i - 1),
                        \'is_first_price\', false
                    ),
                    \'auction\', :auction::text, :tenant::text, NOW()
             FROM generate_series(1, :count::int) AS i',
            [
                'auction' => $auctionId,
                'tenant' => $tenantId,
                'count' => $count,
                'price' => $startPrice,
                'step' => (int) self::STEP_MINOR,
                'firstRound' => $firstRound,
            ],
            [
                'auction' => Types::STRING,
                'tenant' => Types::STRING,
                'count' => Types::INTEGER,
                'price' => Types::INTEGER,
                'step' => Types::INTEGER,
                'firstRound' => Types::INTEGER,
            ],
        );
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (null === $value || !\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('Option "%s" must be a string', $name));
        }

        return $value;
    }
}
