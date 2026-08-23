<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Метрики console-команд (scheduler/worker/webhooks).
 *
 * Покрывает scheduler (auctions:heartbeat, analytics:counters:snapshot,
 * idempotency:cleanup, outbox:cleanup, db:partitions:ensure,
 * notifications:digest:schedule — docker/scheduler-entrypoint.sh),
 * worker (outbox:relay, messenger:consume) и
 * webhooks (messenger:consume webhooks). Сбой команды не виден в HTTP-метриках
 * (практика #15 из observability-best-practices-materials.md) — здесь он
 * становится серией console_commands_failed_total.
 *
 * Метрики:
 * - console_commands_total{command} — счётчик запусков (TERMINATE);
 * - console_commands_failed_total{command} — счётчик падений: exit code != 0
 *   (TERMINATE) и исключения (ERROR; TERMINATE при исключении не
 *   диспатчится, дублей нет);
 * - console_command_duration_seconds{command} — гистограмма длительности
 *   (для long-running messenger:consume значение = время работы до остановки,
 *   интерпретировать как «uptime цикла», не как latency).
 *
 * Агрегация — Redis-адаптер MetricsRegistry (общий для всех контейнеров),
 * поэтому /metrics web-пула видит команды scheduler/worker/webhooks.
 *
 * Устойчивость: метрики НЕ должны ронять команды. Подписчик инжектит
 * MetricsRegistry (а не CollectorRegistry) и получает реестр лениво внутри
 * методов: соединение с Redis (сервис Redis — lazy) происходит только при
 * реальной записи метрики. Сбой Redis/хранилища логируется и пропускается —
 * иначе диспетчер событий инстанцировал бы подписчика (и тянул бы Redis)
 * при КАЖДОМ bin/console, и команды падали бы при недоступном Redis
 * (например, cache:clear в CI).
 */
final class ConsoleMetricsSubscriber implements EventSubscriberInterface
{
    /** @var array<string, int> имя команды → hrtime старта */
    private static array $starts = [];

    public function __construct(
        private readonly MetricsRegistry $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 100],
            ConsoleEvents::TERMINATE => ['onTerminate', -255],
            ConsoleEvents::ERROR => ['onError', -255],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        $name = $command?->getName();
        if (null === $name || '' === $name) {
            return;
        }

        self::$starts[$name] = (int) hrtime(true);
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand();
        $name = null !== $command ? (string) $command->getName() : 'unknown';
        if ('' === $name) {
            $name = 'unknown';
        }

        try {
            $registry = $this->metrics->getCollectorRegistry();
        } catch (\Throwable $e) {
            $this->logMetricsFailure($e);

            return;
        }

        $registry->getOrRegisterCounter('', 'console_commands_total', 'Total console command runs.', ['command'])
            ->inc([$name]);

        if (0 !== $event->getExitCode()) {
            $registry->getOrRegisterCounter('', 'console_commands_failed_total', 'Total failed console command runs (exit code != 0).', ['command'])
                ->inc([$name]);
        }

        if (isset(self::$starts[$name])) {
            $duration = (hrtime(true) - self::$starts[$name]) / 1e9;
            unset(self::$starts[$name]);
            $registry->getOrRegisterHistogram('', 'console_command_duration_seconds', 'Console command duration in seconds.', ['command'])
                ->observe($duration, [$name]);
        }
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $command = $event->getCommand();
        $name = null !== $command ? (string) $command->getName() : 'unknown';
        if ('' === $name) {
            $name = 'unknown';
        }

        try {
            $registry = $this->metrics->getCollectorRegistry();
        } catch (\Throwable $e) {
            $this->logMetricsFailure($e);

            return;
        }

        $registry->getOrRegisterCounter('', 'console_commands_failed_total', 'Total failed console command runs (exit code != 0).', ['command'])
            ->inc([$name]);
    }

    private function logMetricsFailure(\Throwable $e): void
    {
        $this->logger->warning('Console metrics unavailable, command metrics skipped', [
            'error' => $e->getMessage(),
            'exception' => $e::class,
        ]);
    }
}
