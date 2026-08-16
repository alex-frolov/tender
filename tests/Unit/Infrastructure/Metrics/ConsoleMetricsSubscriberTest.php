<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\ConsoleMetricsSubscriber;
use App\Infrastructure\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * ConsoleMetricsSubscriber: метрики НЕ должны ронять console-команды
 * (scheduler/worker/webhooks). Если Redis-хранилище недоступно, подписчик
 * логирует warning и пропускает запись — команда продолжает работать.
 * Это защищает и cache:clear/любой bin/console от падения при недоступном
 * Redis (диспетчер событий инстанцирует подписчика на каждый запуск).
 */
final class ConsoleMetricsSubscriberTest extends TestCase
{
    public function testUnavailableMetricsStorageDoesNotBreakCommandHandling(): void
    {
        // \Redis без соединения: MetricsRegistry::getCollectorRegistry()
        // бросит StorageException (fromExistingConnection требует isConnected).
        $logger = new RecordingLogger();
        $subscriber = new ConsoleMetricsSubscriber(new MetricsRegistry(new \Redis()), $logger);

        $command = new Command('test:metrics-cmd');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        // Ни один вызов не должен бросить исключение при недоступном Redis.
        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));
        $subscriber->onError(new ConsoleErrorEvent($input, $output, new \RuntimeException('boom')));

        self::assertNotEmpty($logger->records);
        self::assertSame('warning', $logger->records[0][0]);
    }

    public function testCommandNameNullIsIgnored(): void
    {
        $logger = new RecordingLogger();
        $subscriber = new ConsoleMetricsSubscriber(new MetricsRegistry(new \Redis()), $logger);

        // Команда без имени: onCommand не должен заводить запись в $starts.
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent(new Command(), $input, $output));

        self::assertSame([], $logger->records);
    }
}

/**
 * Логгер-фикстура: Psr\Log\Test\TestLogger в psr/log v3 отсутствует.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [\is_string($level) ? $level : get_debug_type($level), (string) $message];
    }
}
