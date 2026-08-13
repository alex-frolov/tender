<?php

declare(strict_types=1);

namespace App\Platform\Service;

use App\Platform\Entity\Webhook;

/**
 * Поставщик активных webhook-подписок тенанта (WH-1..7).
 *
 * Выделен в интерфейс для тестируемости WebhookMatcher (без БД); реализация —
 * WebhookRepository (App\Platform\Repository). Возвращает только подписки со
 * статусом active — кандидатов на доставку события; сопоставление по типам
 * событий и фильтрам payload выполняет сам матчер.
 */
interface ActiveWebhooksProviderInterface
{
    /**
     * @return list<Webhook>
     */
    public function findActiveForTenant(string $tenantId): array;
}
