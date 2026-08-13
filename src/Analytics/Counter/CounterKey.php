<?php

declare(strict_types=1);

namespace App\Analytics\Counter;

use App\Analytics\Entity\Enum\AnalyticsMetricEnum;

/**
 * Redis-ключ real-time счётчика аналитики (ARCH-9, data-model §2.14a).
 *
 * Формат: `ctr:{tenant}:{metric}:{date}` для счётчика без среза и
 * `ctr:{tenant}:{metric}:{date}:{dimKey}` со срезом, где dimKey — base64url
 * от канонического JSON среза (без символа ':' — ключ парсится разбиением).
 * date — день в UTC (Y-m-d). Снапшот-джоб сканирует префикс `ctr:*`, парсит
 * ключ (fromKey) и накапливает значение в `analytics_counters` (PG).
 */
final readonly class CounterKey
{
    private const string PREFIX = 'ctr';

    public function __construct(
        private string $tenantId,
        private AnalyticsMetricEnum $metric,
        private \DateTimeImmutable $date,
        /** @var array<string, mixed> */
        private array $dimension,
    ) {
    }

    /**
     * @param array<string, mixed> $dimension
     */
    public static function build(
        string $tenantId,
        AnalyticsMetricEnum $metric,
        \DateTimeImmutable $date,
        array $dimension = [],
    ): self {
        return new self($tenantId, $metric, $date, self::normalizeDimension($dimension));
    }

    /**
     * Разбор ключа из Redis (сканирование снапшот-джоба). Невалидный/чужой
     * ключ (не ctr:*) → null (джоб пропускает).
     */
    public static function fromKey(string $key): ?self
    {
        $parts = explode(':', $key);
        if (self::PREFIX !== ($parts[0] ?? null) || \count($parts) < 4) {
            return null;
        }

        $metric = AnalyticsMetricEnum::tryFrom($parts[2]);
        if (null === $metric) {
            return null;
        }

        $date = self::parseDate($parts[3]);
        if (null === $date) {
            return null;
        }

        $dimension = self::decodeDimension($parts[4] ?? null);
        if (null === $dimension) {
            return null;
        }

        return new self($parts[1], $metric, $date, $dimension);
    }

    public function key(): string
    {
        $base = \sprintf('%s:%s:%s:%s', self::PREFIX, $this->tenantId, $this->metric->value, $this->date->format('Y-m-d'));
        if ([] === $this->dimension) {
            return $base;
        }

        return $base.':'.self::encodeDimension($this->dimension);
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function metric(): AnalyticsMetricEnum
    {
        return $this->metric;
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * @return array<string, mixed>
     */
    public function dimension(): array
    {
        return $this->dimension;
    }

    /**
     * Канонический JSON среза: отсортированные ключи (рекурсивно), без пробелов —
     * единая форма для уникальности в PG (jsonb) и аддитивного upsert'а.
     *
     * @param array<string, mixed> $dimension
     */
    public static function canonicalJson(array $dimension): string
    {
        if ([] === $dimension) {
            return '{}';
        }

        $json = json_encode(self::normalizeDimension($dimension), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            throw new \LogicException('Unable to encode analytics dimension');
        }

        return $json;
    }

    /**
     * Каноническая форма среза: рекурсивная сортировка ключей по алфавиту и
     * приведение ключей к строке — ключ Redis и dimension PG детерминированы
     * от порядка ключей входа (JSON-срезы всегда со строковыми ключами).
     *
     * @param array<mixed, mixed> $dimension
     *
     * @return array<string, mixed>
     */
    private static function normalizeDimension(array $dimension): array
    {
        $normalized = [];
        foreach ($dimension as $key => $value) {
            $normalized[(string) $key] = \is_array($value) ? self::normalizeDimension($value) : $value;
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * Строгий разбор даты периода Y-m-d (UTC). Невалидные даты (2026-13-45,
     * 2026-02-30) → null — не допускаются в счётчиках.
     */
    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($value.'T00:00:00+00:00');
    }

    /**
     * @param array<string, mixed> $dimension
     */
    private static function encodeDimension(array $dimension): string
    {
        return rtrim(strtr(base64_encode(self::canonicalJson($dimension)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeDimension(?string $encoded): ?array
    {
        if (null === $encoded || '' === $encoded) {
            return [];
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $json) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? self::normalizeDimension($decoded) : null;
    }
}
