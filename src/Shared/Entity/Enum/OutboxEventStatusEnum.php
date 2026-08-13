<?php

declare(strict_types=1);

namespace App\Shared\Entity\Enum;

/**
 * Статус outbox-события (ARCH-3, NFR-5):
 * pending — ждёт релиза, published — отправлено в транспорт.
 */
enum OutboxEventStatusEnum: string
{
    case PENDING = 'pending';
    case PUBLISHED = 'published';

    public function isPublished(): bool
    {
        return self::PUBLISHED === $this;
    }
}
