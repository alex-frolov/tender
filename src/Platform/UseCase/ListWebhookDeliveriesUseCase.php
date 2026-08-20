<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Presenter\WebhookPresenter;
use App\Platform\WebhookService;
use App\Shared\Input\Paginator;
use App\Shared\Repository\KeysetCursor;

/**
 * Журнал доставок webhook-подписки (WH-2..6, GET /webhooks/{id}/deliveries).
 * Доставки (статус, попытки, ошибка, next_retry) — для диагностики dead-letter.
 */
final readonly class ListWebhookDeliveriesUseCase implements PlatformUseCase
{
    public function __construct(
        private WebhookService $webhooks,
        private WebhookPresenter $presenter,
    ) {
    }

    /**
     * Keyset-пагинация (AR-6): страницу по (created_at, id) DESC отдаёт БД
     * (журнал доставок не ограничен по размеру, in-memory срез недопустим),
     * запрашивается limit+1 строк — лишняя означает следующую страницу.
     * next_cursor — единый OPAQUE-курсор контракта.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(User $user, string $webhookId, Paginator $paginator = new Paginator()): array
    {
        $limit = $paginator->limitValue();
        $cursor = KeysetCursor::decode($paginator->cursor);

        $rows = $this->webhooks->listDeliveries(
            $user,
            $webhookId,
            $cursor?->createdAt,
            $cursor?->id,
            $limit + 1,
        );

        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $nextCursor = null;
        if ($hasMore && [] !== $rows) {
            $last = $rows[\count($rows) - 1];
            $nextCursor = KeysetCursor::encode($last->getCreatedAt(), $last->getId());
        }

        $items = [];
        foreach ($rows as $delivery) {
            $items[] = $this->presenter->delivery($delivery);
        }

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
