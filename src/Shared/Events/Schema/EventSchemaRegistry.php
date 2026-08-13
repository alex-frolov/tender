<?php

declare(strict_types=1);

namespace App\Shared\Events\Schema;

use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Реестр JSON Schema событий (schema registry, architecture/testing-strategy.md §5).
 *
 * Каждое событие домена может иметь JSON Schema (config/schemas/events/{event_type}.json,
 * конверт + payload). Реестр загружает схему по типу события, валидирует конверт и
 * возвращает человекочитаемые ошибки. Событий без схемы (схемы добавляются по мере
 * реализации) валидация не касается — реестр возвращает пустой список ошибок.
 *
 * Валидация выполняется на write-границе (OutboxEventSchemaListener, prePersist):
 * нарушение контракта роняет транзакцию (fail fast, событие не попадает в outbox).
 */
final class EventSchemaRegistry
{
    /** @var array<string, object|null> кэш: event_type => декодированная схема (null если нет) */
    private array $schemaCache = [];

    private Validator $validator;

    public function __construct(private readonly string $schemasDir)
    {
        $this->validator = new Validator();
    }

    /**
     * Валидация конверта события (event_id, event_type, occurred_at, tenant_id,
     * aggregate_type, aggregate_id, payload) против схемы типа события.
     *
     * @param array<string, mixed> $envelope
     *
     * @return list<string> ошибки валидации; [] — схема отсутствует или конверт корректен
     */
    public function validateEnvelope(array $envelope): array
    {
        $eventType = $envelope['event_type'] ?? '';
        if (!\is_string($eventType) || '' === $eventType) {
            return ['event_type is required'];
        }

        $schema = $this->load($eventType);
        if (null === $schema) {
            return [];
        }

        $result = $this->validator->validate(Helper::convertAssocArrayToObject($envelope), $schema);
        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        $error = $result->error();
        if (null !== $error) {
            $this->collect($error, $errors);
        }

        return $errors;
    }

    /**
     * Загрузка схемы типа события.
     *
     * @return object|null декодированная JSON Schema; null если схема не зарегистрирована
     */
    private function load(string $eventType): ?object
    {
        if (\array_key_exists($eventType, $this->schemaCache)) {
            return $this->schemaCache[$eventType];
        }

        $schema = null;
        $path = $this->schemasDir.\DIRECTORY_SEPARATOR.$eventType.'.json';
        if (is_file($path)) {
            $content = file_get_contents($path);
            if (false === $content) {
                throw new \RuntimeException(\sprintf('Unable to read event schema "%s"', $path));
            }
            $schema = json_decode($content, false);
            if (!$schema instanceof \stdClass) {
                throw new \RuntimeException(\sprintf('Event schema "%s" is not a valid JSON object', $path));
            }
        }

        return $this->schemaCache[$eventType] = $schema;
    }

    /**
     * @param list<string> $out
     */
    private function collect(\Opis\JsonSchema\Errors\ValidationError $error, array &$out): void
    {
        $out[] = $this->format($error);
        foreach ($error->subErrors() as $subError) {
            if ($subError instanceof \Opis\JsonSchema\Errors\ValidationError) {
                $this->collect($subError, $out);
            }
        }
    }

    private function format(\Opis\JsonSchema\Errors\ValidationError $error): string
    {
        $message = $error->message();
        foreach ($error->args() as $key => $value) {
            if ($value instanceof \UnitEnum) {
                $message = str_replace('{'.$key.'}', $value->name, $message);
            } elseif (\is_scalar($value) || $value instanceof \Stringable) {
                $message = str_replace('{'.$key.'}', (string) $value, $message);
            }
        }

        $pointer = implode('/', array_map(static function (mixed $segment): string {
            if (\is_scalar($segment)) {
                return (string) $segment;
            }
            $encoded = json_encode($segment);

            return false !== $encoded ? $encoded : '';
        }, $error->schema()->info()->path()));

        return \sprintf('[%s] %s (schema: /%s)', $error->keyword(), $message, $pointer);
    }
}
