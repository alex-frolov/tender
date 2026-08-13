<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Events;

use App\Shared\Events\Schema\EventSchemaRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Реестр JSON Schema событий (schema registry, testing-strategy.md §5):
 * - поведение реестра: загрузка схемы по типу события и валидация конверта
 *   (payload/event_type/aggregate_type + envelope-форматы uuid/date-time);
 * - структурная целостность: каждый файл
 *   config/schemas/events/{event_type}.json — валидный JSON draft 2020-12 с
 *   единым конвертом (все поля required), event_type.const == имени файла,
 *   aggregate_type.const задан, payload: object + additionalProperties=false.
 *
 * Покрытие «каждое событие events.md имеет схему» проверяется в CI отдельным
 * шагом scripts/check-event-schemas.php (composer schema:check).
 */
final class EventSchemaRegistryTest extends TestCase
{
    private string $schemasDir;

    protected function setUp(): void
    {
        $this->schemasDir = \dirname(__DIR__, 4).'/config/schemas/events';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function envelope(string $eventType, array $payload, string $aggregateType = 'tender', ?string $tenantId = null): array
    {
        return [
            'event_id' => (string) Uuid::v4(),
            'event_type' => $eventType,
            'occurred_at' => '2026-08-13T10:00:00+00:00',
            'tenant_id' => $tenantId ?? (string) Uuid::v4(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) Uuid::v4(),
            'payload' => $payload,
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemaFiles(): iterable
    {
        $dir = \dirname(__DIR__, 4).'/config/schemas/events';
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $eventType = basename($file, '.json');
            yield $eventType => [$eventType];
        }
    }

    public function testRegisteredEventTypeValidEnvelopePasses(): void
    {
        $registry = new EventSchemaRegistry($this->schemasDir);

        self::assertSame([], $registry->validateEnvelope($this->envelope(
            'auction.bid',
            ['auction_id' => (string) Uuid::v4(), 'bid_id' => (string) Uuid::v4(), 'price_minor' => 1000, 'round' => 1],
            'auction',
        )));
    }

    public function testPayloadMissingRequiredFieldFails(): void
    {
        $registry = new EventSchemaRegistry($this->schemasDir);

        // auction.bid требует price_minor и round — их нет.
        $errors = $registry->validateEnvelope($this->envelope(
            'auction.bid',
            ['auction_id' => (string) Uuid::v4(), 'bid_id' => (string) Uuid::v4()],
            'auction',
        ));

        self::assertNotSame([], $errors);
        $joined = implode('; ', $errors);
        self::assertStringContainsString('required', $joined);
        self::assertStringContainsString('payload', $joined);
    }

    public function testPayloadWithUndeclaredFieldFails(): void
    {
        $registry = new EventSchemaRegistry($this->schemasDir);

        // additionalProperties: false — неизвестное поле в payload нарушает контракт.
        $errors = $registry->validateEnvelope($this->envelope(
            'auction.bid',
            [
                'auction_id' => (string) Uuid::v4(),
                'bid_id' => (string) Uuid::v4(),
                'price_minor' => 1000,
                'round' => 1,
                'undeclared_field' => true,
            ],
            'auction',
        ));

        self::assertNotSame([], $errors);
    }

    public function testWrongAggregateTypeFails(): void
    {
        $registry = new EventSchemaRegistry($this->schemasDir);

        // auction.bid требует aggregate_type=auction (const).
        $errors = $registry->validateEnvelope($this->envelope(
            'auction.bid',
            ['auction_id' => (string) Uuid::v4(), 'bid_id' => (string) Uuid::v4(), 'price_minor' => 1000, 'round' => 1],
            'tender',
        ));

        self::assertNotSame([], $errors);
    }

    public function testUnregisteredEventTypeIsSkipped(): void
    {
        $registry = new EventSchemaRegistry($this->schemasDir);

        self::assertSame([], $registry->validateEnvelope($this->envelope(
            'test.relay',
            ['anything' => true],
        )));
    }

    public function testInvalidEnvelopeWithoutEventTypeFails(): void
    {
        $registry = new EventSchemaRegistry($this->schemasDir);

        self::assertNotSame([], $registry->validateEnvelope(['payload' => []]));
    }

    #[DataProvider('schemaFiles')]
    public function testSchemaIsValidJsonAndEnvelopeConsistent(string $eventType): void
    {
        $file = $this->schemasDir.'/'.$eventType.'.json';
        $decoded = json_decode((string) file_get_contents($file), false);

        self::assertIsObject($decoded, \sprintf('schema %s.json must be valid JSON object', $eventType));
        self::assertInstanceOf(\stdClass::class, $decoded);
        $schema = $decoded;

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema->{'$schema'} ?? null);
        self::assertSame($eventType, $schema->title ?? null);
        self::assertSame('object', $schema->type ?? null);

        // Единый конверт: все поля обязательны.
        $required = $schema->required ?? [];
        self::assertIsArray($required);
        foreach (['event_id', 'event_type', 'occurred_at', 'tenant_id', 'aggregate_type', 'aggregate_id', 'payload'] as $field) {
            self::assertContains($field, $required, \sprintf('envelope field %s must be required for %s', $field, $eventType));
        }

        $properties = $schema->properties ?? new \stdClass();
        self::assertInstanceOf(\stdClass::class, $properties);
        $eventTypeProp = $properties->event_type ?? new \stdClass();
        self::assertInstanceOf(\stdClass::class, $eventTypeProp);
        self::assertSame($eventType, $eventTypeProp->const ?? null, 'event_type.const must match filename');
        $aggregateProp = $properties->aggregate_type ?? new \stdClass();
        self::assertInstanceOf(\stdClass::class, $aggregateProp);
        self::assertIsString($aggregateProp->const ?? null, 'aggregate_type.const must be present');

        $payload = $properties->payload ?? new \stdClass();
        self::assertInstanceOf(\stdClass::class, $payload);
        self::assertSame('object', $payload->type ?? null, 'payload.type must be object');
        self::assertFalse($payload->additionalProperties ?? null, 'payload.additionalProperties must be false');
        self::assertIsObject($payload->properties ?? null, 'payload.properties must be an object');
    }

    /**
     * Реестр обязан загрузить каждую схему без ошибки (валидность файла):
     * валидация против каждой зарегистрированной схемы не бросает исключений
     * (реестр возвращает список ошибок, а не исключение). Падение — невалидный
     * JSON/нечитаемый файл → RuntimeException → тест падает (fail fast).
     */
    #[DataProvider('schemaFiles')]
    public function testRegistryLoadsEverySchema(string $eventType): void
    {
        // Assertion-ов нет намеренно: цель — убедиться, что схема загружается
        // без исключений (валидный JSON-объект). Риск-статус отключаем явно.
        $this->expectNotToPerformAssertions();

        $registry = new EventSchemaRegistry($this->schemasDir);

        $registry->validateEnvelope($this->envelope($eventType, []));
    }
}
