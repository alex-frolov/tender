<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\DocumentMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * DocumentMetricsCollector: document_uploads_total{outcome},
 * document_bytes_total, document_storage_errors_total — контракт P1-8.
 */
final class DocumentMetricsCollectorTest extends TestCase
{
    public function testUploadOutcomesAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new DocumentMetricsCollector($registry);

        $collector->uploadFinished('ok');
        $collector->uploadFinished('ok');
        $collector->uploadFinished('invalid');
        $collector->uploadFinished('storage_error');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('document_uploads_total{outcome="ok"} 2', $body);
        self::assertStringContainsString('document_uploads_total{outcome="invalid"} 1', $body);
        self::assertStringContainsString('document_uploads_total{outcome="storage_error"} 1', $body);
    }

    public function testBytesStoredAccumulates(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new DocumentMetricsCollector($registry);

        $collector->bytesStored(100);
        $collector->bytesStored(250);
        // Ноль/отрицательные не пишутся (нечего хранить).
        $collector->bytesStored(0);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('document_bytes_total 350', $body);
    }

    public function testStorageErrorsAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new DocumentMetricsCollector($registry);

        $collector->storageError();

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('document_storage_errors_total 1', $body);
    }
}
