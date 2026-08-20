<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Repository;

use Symfony\Component\Uid\Uuid;

/**
 * Строка списка для проверки KeysetCursor::sliceAfter: минимальный носитель
 * ключа (created_at, id). Срез работает с объектами (@template T of object),
 * поэтому фикстура — объект, а не массив.
 */
final readonly class CursorRow
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public string $id = '',
    ) {
    }

    public static function at(int $second): self
    {
        return new self(
            new \DateTimeImmutable(\sprintf('2026-08-20T10:00:%02dZ', $second), new \DateTimeZone('UTC')),
            (string) Uuid::v7(),
        );
    }
}
