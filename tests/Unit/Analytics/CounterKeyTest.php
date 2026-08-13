<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Analytics\Counter\CounterKey;
use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use PHPUnit\Framework\TestCase;

/**
 * Redis-ключ real-time счётчика аналитики (ARCH-9).
 *
 * Формат: ctr:{tenant}:{metric}:{date} (+ base64url среза dimension).
 * Round-trip build → key → fromKey; невалидные/чужие ключи (не ctr:*)
 * разбираются в null (снапшот-джоб пропускает).
 */
final class CounterKeyTest extends TestCase
{
    public function testBuildWithoutDimension(): void
    {
        $key = CounterKey::build('t1', AnalyticsMetricEnum::AUCTIONS_TOTAL, new \DateTimeImmutable('2026-08-12'));
        self::assertSame('ctr:t1:auctions_total:2026-08-12', $key->key());
    }

    public function testBuildWithDimension(): void
    {
        $key = CounterKey::build(
            't1',
            AnalyticsMetricEnum::BIDS_BY_STATUS,
            new \DateTimeImmutable('2026-08-12'),
            ['status' => 'admit'],
        );
        self::assertStringStartsWith('ctr:t1:bids_by_status:2026-08-12:', $key->key());
        self::assertStringNotContainsString(':', substr($key->key(), \strlen('ctr:t1:bids_by_status:2026-08-12:')));
    }

    public function testRoundTrip(): void
    {
        $original = CounterKey::build(
            'tenant-uuid-1',
            AnalyticsMetricEnum::TENDERS_BY_STATUS,
            new \DateTimeImmutable('2026-08-12'),
            ['status' => 'opened', 'region' => '77'],
        );
        $parsed = CounterKey::fromKey($original->key());

        self::assertNotNull($parsed);
        self::assertSame($original->tenantId(), $parsed->tenantId());
        self::assertSame($original->metric(), $parsed->metric());
        self::assertSame('2026-08-12', $parsed->date()->format('Y-m-d'));
        self::assertSame($original->dimension(), $parsed->dimension());
    }

    public function testRoundTripWithoutDimension(): void
    {
        $original = CounterKey::build('t1', AnalyticsMetricEnum::CONTRACTS_TOTAL, new \DateTimeImmutable('2026-08-12'));
        $parsed = CounterKey::fromKey($original->key());

        self::assertNotNull($parsed);
        self::assertSame([], $parsed->dimension());
    }

    public function testFromKeyRejectsForeignPrefix(): void
    {
        self::assertNull(CounterKey::fromKey('auction:state:abc'));
    }

    public function testFromKeyRejectsUnknownMetric(): void
    {
        self::assertNull(CounterKey::fromKey('ctr:t1:unknown_metric:2026-08-12'));
    }

    public function testFromKeyRejectsMalformedDate(): void
    {
        self::assertNull(CounterKey::fromKey('ctr:t1:auctions_total:2026-13-45'));
    }

    public function testCanonicalJsonSortsKeys(): void
    {
        self::assertSame(
            '{"region":"77","status":"opened"}',
            CounterKey::canonicalJson(['status' => 'opened', 'region' => '77']),
        );
        self::assertSame('{}', CounterKey::canonicalJson([]));
    }
}
