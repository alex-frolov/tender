<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Метрики console-команд (scheduler/worker/webhooks).
 *
 * Покрывает scheduler (auctions:heartbeat, analytics:counters:snapshot,
 * idempotency:cleanup, notifications:digest:schedule — docker/
 * scheduler-entrypoint.sh), worker (outbox:relay, messenger:consume) и
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
 */
final class ConsoleMetricsSubscriber implements EventSubscriberInterface
{
    /** @var array<string, int> имя команды → hrtime старта */
    private static array $starts = [];

    public function __construct(private readonly CollectorRegistry $registry)
    {
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
        if (null === $command || '' === $command->getName()) {
            return;
        }

        self::$starts[$command->getName()] = hrtime(true);
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand();
        $name = null !== $command ? (string) $command->getName() : 'unknown';
        if ('' === $name) {
            $name = 'unknown';
        }

        $this->registry->getOrRegisterCounter('', 'console_commands_total', 'Total console command runs.', ['command'])
            ->inc([$name]);

        if (0 !== $event->getExitCode()) {
            $this->registry->getOrRegisterCounter('', 'console_commands_failed_total', 'Total failed console command runs (exit code != 0).', ['command'])
                ->inc([$name]);
        }

        if (isset(self::$starts[$name])) {
            $duration = (hrtime(true) - self::$starts[$name]) / 1e9;
            unset(self::$starts[$name]);
            $this->registry->getOrRegisterHistogram('', 'console_command_duration_seconds', 'Console command duration in seconds.', ['command'])
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

        $this->registry->getOrRegisterCounter('', 'console_commands_failed_total', 'Total failed console command runs (exit code != 0).', ['command'])
            ->inc([$name]);
    }
}
