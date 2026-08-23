<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Нарезка месячных партиций вперёд.
 *
 * Партиционированы audit_log (RANGE created_at) и auction_bids (RANGE
 * placed_at). У обеих есть DEFAULT-партиция — без неё INSERT в месяц, для
 * которого партиции нет, падал бы, а это отказ записи аудита (то есть отказ
 * самой мутации) и отказ приёма ставки в живых торгах. Но DEFAULT — страховка,
 * а не рабочий режим: попав в неё, строки теряют смысл партиционирования
 * (retention нечего отсоединять, pruning не работает).
 *
 * Команда идемпотентна (CREATE TABLE IF NOT EXISTS) и запускается из
 * планировщика раз в сутки (docker/scheduler-entrypoint.sh): она держит запас
 * партиций на `--months` вперёд (по умолчанию 3), поэтому в норме DEFAULT
 * остаётся пустой.
 *
 * ВАЖНО: если строки уже попали в DEFAULT, создание партиции на их месяц
 * PostgreSQL отклонит (в DEFAULT есть конфликтующие строки). Команда сообщает
 * об этом ошибкой, а не молчит: разбирать такой случай нужно руками
 * (отсоединить DEFAULT, перелить строки, присоединить обратно).
 */
#[AsCommand(name: 'db:partitions:ensure', description: 'Create monthly partitions ahead for audit_log and auction_bids')]
final class PartitionEnsureCommand extends Command
{
    /**
     * Партиционированные таблицы. Ключ партиционирования у обеих — момент
     * создания строки: audit_log — created_at, auction_bids — placed_at;
     * задан в схеме (PARTITION BY RANGE), здесь не дублируется.
     *
     * @var list<string>
     */
    private const array TABLES = ['audit_log', 'auction_bids'];

    /** Запас партиций вперёд по умолчанию (месяцев). */
    private const int DEFAULT_AHEAD_MONTHS = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'months',
            null,
            InputOption::VALUE_REQUIRED,
            'How many months ahead to pre-create partitions',
            (string) self::DEFAULT_AHEAD_MONTHS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Не-число осознанно превращается в 0 — отсекается проверкой ниже
        // как явная ошибка ввода, а не молчаливый откат к дефолту.
        $monthsOption = $input->getOption('months');
        $months = is_numeric($monthsOption) ? (int) $monthsOption : 0;
        if ($months < 1) {
            $io->error('Months ahead must be at least 1');

            return Command::INVALID;
        }

        $created = [];
        // Текущий месяц включается всегда: команда должна чинить и ситуацию,
        // когда партиция на «сейчас» не создана (свежая БД, пропуск запусков).
        $start = $this->clock->now()->setTime(0, 0)->modify('first day of this month');

        foreach (self::TABLES as $table) {
            if (!$this->isPartitioned($table)) {
                $io->warning(\sprintf('%s is not a partitioned table — skipped', $table));

                continue;
            }

            for ($offset = 0; $offset <= $months; ++$offset) {
                $from = $start->modify(\sprintf('+%d months', $offset));
                $partition = \sprintf('%s_%s', $table, $from->format('Y_m'));
                if ($this->createPartition($table, $partition, $from)) {
                    $created[] = $partition;
                }
            }
        }

        if ([] === $created) {
            $io->success('All monthly partitions already exist');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Created %d partition(s): %s', \count($created), implode(', ', $created)));

        return Command::SUCCESS;
    }

    /**
     * Партиционирована ли таблица (relkind = 'p'). Проверка нужна, чтобы
     * команда была безопасна на БД, накатанной до миграций партиционирования:
     * CREATE TABLE ... PARTITION OF обычной таблицы — ошибка, а не no-op.
     */
    private function isPartitioned(string $table): bool
    {
        $relkind = $this->connection->fetchOne(
            'SELECT c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?',
            [$table],
        );

        return 'p' === $relkind;
    }

    /**
     * @return bool true — партиция создана этим вызовом
     */
    private function createPartition(string $table, string $partition, \DateTimeImmutable $from): bool
    {
        if ($this->partitionExists($partition)) {
            return false;
        }

        $to = $from->modify('+1 month');

        // Имена таблиц — из константы класса и календаря, не из ввода;
        // границы диапазона в PARTITION OF ... FOR VALUES параметрами не
        // передаются (это DDL), поэтому подставляются форматированными датами.
        $this->connection->executeStatement(\sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
            $this->connection->quoteSingleIdentifier($partition),
            $this->connection->quoteSingleIdentifier($table),
            $this->connection->quote($from->format('Y-m-d')),
            $this->connection->quote($to->format('Y-m-d')),
        ));

        return true;
    }

    private function partitionExists(string $partition): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?',
            [$partition],
        );
    }
}
