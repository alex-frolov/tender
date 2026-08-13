<?php

declare(strict_types=1);

namespace App\Export;

/**
 * Нормализация значений строк экспорта (UC-31, AM-15).
 *
 * Строки из Doctrine array-гидрации (toIterable, HYDRATE_ARRAY) имеют
 * фактические типы, зависящие от драйвера и маппинга (uuid → string,
 * bigint → int/string, enumType → экземпляр enum, datetime → DateTimeImmutable).
 * Хелперы приводят значение к строке/int без PHPStan-небезопасных кастов mixed.
 */
final class ExportRowValues
{
    /**
     * Значение → строка (для ячеек xlsx/csv). Дата форматируется ISO-8601 UTC,
     * enum — по значению, null/прочее — пустая строка.
     */
    public static function string(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Значение → int либо null (для денежных колонок; minor units, PR-1..11).
     */
    public static function intOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && '' !== $value && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }
}
