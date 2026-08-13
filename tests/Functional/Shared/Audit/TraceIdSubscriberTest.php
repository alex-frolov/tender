<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Audit;

use App\Shared\Audit\TraceContext;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NFR-12/21: trace-id сквозной.
 * - без заголовка — генерируется UUID и отдаётся в X-Trace-Id;
 * - с валидным X-Trace-Id — принимается и возвращается;
 * - невалидный — игнорируется, генерируется новый.
 */
final class TraceIdSubscriberTest extends WebTestCase
{
    public function testGeneratesTraceIdWhenAbsent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/live');

        self::assertResponseIsSuccessful();
        $traceId = $client->getResponse()->headers->get('X-Trace-Id');
        self::assertNotNull($traceId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $traceId);

        // контекст содержит тот же id
        $context = static::getContainer()->get(TraceContext::class);
        self::assertSame($traceId, $context->getTraceId());
    }

    public function testAcceptsIncomingTraceId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/live', server: ['HTTP_X_TRACE_ID' => 'my-trace-42']);

        self::assertResponseIsSuccessful();
        self::assertSame('my-trace-42', $client->getResponse()->headers->get('X-Trace-Id'));
    }

    public function testRejectsInvalidTraceId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/live', server: ['HTTP_X_TRACE_ID' => 'bad id with spaces!']);

        self::assertResponseIsSuccessful();
        $traceId = $client->getResponse()->headers->get('X-Trace-Id');
        self::assertNotNull($traceId);
        self::assertNotSame('bad id with spaces!', $traceId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $traceId);
    }
}
