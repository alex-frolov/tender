<?php

declare(strict_types=1);

namespace App\Analytics\Entity;

use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Агрегат аналитики (ARCH-9, domain/data-model.md §2.14a).
 *
 * Значение счётчика на (tenant, metric, period, dimension). Таблица — целевая
 * витрина: фоновый джоб периодически снимает снапшот Redis-счётчиков и
 * накапливает значения аддитивно (upsert ON CONFLICT ... value + EXCLUDED.value).
 *
 * - period — дата периода (день; месяц/неделя — агрегация на чтении);
 * - dimension — срез (jsonb: регион/ОКПД2/заказчик/исполнитель; '{}' без среза),
 *   каноническая форма (отсортированные ключи) для уникальности;
 * - value — bigint (minor units для сумм; счётчик для количеств).
 *
 * Материализованные представления запрещены (риск RAM при росте и частых
 * изменениях торгов, ARCH-9): чтение — Redis (свежие) → PG (пересчитанные).
 * Запись выполняется нативным upsert'ом (AnalyticsCounterRepository::increment),
 * ORM используется для чтения/тестов.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_counters')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_counters', columns: ['tenant_id', 'metric', 'period', 'dimension'])]
#[ORM\Index(name: 'idx_analytics_counters_tenant_metric_period', columns: ['tenant_id', 'metric', 'period'])]
#[ORM\Index(name: 'idx_analytics_counters_dimension', columns: ['dimension'])]
class AnalyticsCounter
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    #[ORM\GeneratedValue]
    /** @var int|null Doctrine присваивает id через reflection */
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'string', length: 40, enumType: AnalyticsMetricEnum::class)]
    private AnalyticsMetricEnum $metric;

    /** Дата периода (день), UTC. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $period;

    /** @var array<string, mixed> срез (jsonb; каноническая форма) */
    #[ORM\Column(type: 'json')]
    private array $dimension;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $value;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $dimension
     */
    public function __construct(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        \DateTimeImmutable $period,
        array $dimension = [],
        int $value = 0,
    ) {
        $this->tenantId = $tenantId;
        $this->metric = $metric;
        $this->period = $period;
        $this->dimension = $dimension;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getMetric(): AnalyticsMetricEnum
    {
        return $this->metric;
    }

    public function getPeriod(): \DateTimeImmutable
    {
        return $this->period;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDimension(): array
    {
        return $this->dimension;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): void
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
