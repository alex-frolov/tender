<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Platform\Service\WebhookPayloadBuilder;
use App\Shared\Events\EventMessage;
use PHPUnit\Framework\TestCase;

/**
 * Формирование тела webhook-запроса (WH-2): канонический JSON события +
 * конверт (event_id, event_type, occurred_at, tenant_id, aggregate, data).
 */
final class WebhookPayloadBuilderTest extends TestCase
{
    public function testBuildIncludesEnvelopeAndData(): void
    {
        $builder = new WebhookPayloadBuilder();
        $message = EventMessage::create(
            eventType: 'tender.published',
            tenantId: 'tenant-1',
            aggregateType: 'tender',
            aggregateId: 'tender-1',
            payload: ['tender_id' => 'tender-1', 'number' => 'T-1'],
        );

        $json = $builder->build($message);

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame($message->eventId, $decoded['event_id']);
        self::assertSame('tender.published', $decoded['event_type']);
        self::assertSame('tenant-1', $decoded['tenant_id']);
        $aggregate = $decoded['aggregate'];
        self::assertIsArray($aggregate);
        self::assertSame('tender', $aggregate['type']);
        self::assertSame('tender-1', $aggregate['id']);
        $occurredAt = $decoded['occurred_at'];
        self::assertIsString($occurredAt);
        self::assertSame(['tender_id' => 'tender-1', 'number' => 'T-1'], $decoded['data']);
        self::assertSame('Z', substr($occurredAt, -1));
    }

    public function testBuildIsCanonicalJson(): void
    {
        $builder = new WebhookPayloadBuilder();
        $message = EventMessage::create('contract.signed', 'tenant-1', 'contract', 'c-1', ['price' => 100]);

        $json = $builder->build($message);
        self::assertStringContainsString('"price":100', $json);
        // Без экранирования слэшей/юникода (детерминированная подпись, WH-3)
        self::assertStringNotContainsString('\\/', $json);
    }
}
