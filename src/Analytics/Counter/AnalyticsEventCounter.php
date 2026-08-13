<?php

declare(strict_types=1);

namespace App\Analytics\Counter;

use App\Analytics\CounterService;
use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use App\Shared\Events\EventMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Инкремент Redis-счётчиков из потока доменных событий (ARCH-9, modules.md §8:
 * модуль analytics «потребляет события»). Вызывается из консьюмера доменных
 * событий (EventMessageHandler, outbox → RabbitMQ).
 *
 * Маппинг событие → инкременты (метрика + срез dimension + сумма):
 * - tender.opened → tenders_by_status {status: opened};
 * - auction.started → auctions_total;
 * - bid.qualified → bids_by_status {status: decision из payload};
 * - contract.signed → contracts_total + contracts_amount_sum (+price_net_minor).
 *
 * Остальные метрики каталога (tenders_total/bids_total/avg_price_reduction/
 * execution_rating_avg) заводятся на события по мере их эмиссии (задачи 6.3/6.8).
 * События без тенанта (системные, platform.*) счётчики не инкрементят.
 */
final class AnalyticsEventCounter
{
    public function __construct(
        private readonly CounterService $counters,
    ) {
    }

    public function apply(EventMessage $message): void
    {
        if (null === $message->tenantId) {
            return;
        }

        $tenantId = Uuid::fromString($message->tenantId);

        foreach ($this->incrementsFor($message->eventType, $message->payload) as $increment) {
            // CounterService сам обрабатывает сбой Redis (лог + no-op): счётчики —
            // best-effort, доменная обработка события не падает (ARCH-9).
            $this->counters->increment(
                $tenantId,
                $increment['metric'],
                $increment['dimension'],
                $increment['amount'],
            );
        }
    }

    /**
     * Инкременты для события. dimension — канонический срез (jsonb в PG).
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array{metric: AnalyticsMetricEnum, dimension: array<string, mixed>, amount: int}>
     */
    private function incrementsFor(string $eventType, array $payload): array
    {
        return match ($eventType) {
            'tender.opened' => [[
                'metric' => AnalyticsMetricEnum::TENDERS_BY_STATUS,
                'dimension' => ['status' => 'opened'],
                'amount' => 1,
            ]],
            'auction.started' => [[
                'metric' => AnalyticsMetricEnum::AUCTIONS_TOTAL,
                'dimension' => [],
                'amount' => 1,
            ]],
            'bid.qualified' => [[
                'metric' => AnalyticsMetricEnum::BIDS_BY_STATUS,
                'dimension' => ['status' => self::stringField($payload, 'decision')],
                'amount' => 1,
            ]],
            'contract.signed' => [
                [
                    'metric' => AnalyticsMetricEnum::CONTRACTS_TOTAL,
                    'dimension' => [],
                    'amount' => 1,
                ],
                [
                    'metric' => AnalyticsMetricEnum::CONTRACTS_AMOUNT_SUM,
                    'dimension' => [],
                    'amount' => self::intField($payload, 'price_net_minor'),
                ],
            ],
            default => [],
        };
    }

    /**
     * Строковое поле payload для среза (пустая строка, если поля нет).
     *
     * @param array<string, mixed> $payload
     */
    private static function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * Целочисленное поле payload (0, если поля нет/не число).
     *
     * @param array<string, mixed> $payload
     */
    private static function intField(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
