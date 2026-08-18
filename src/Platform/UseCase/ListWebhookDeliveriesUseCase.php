<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Entity\WebhookDelivery;
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
     * Keyset-пагинация (AR-6): in-memory срез по (created_at, id) над журналом
     * доставок (новые сверху); next_cursor — единый OPAQUE-курсор контракта.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(User $user, string $webhookId, Paginator $paginator = new Paginator()): array
    {
        [$page, $nextCursor] = KeysetCursor::sliceAfter(
            $this->webhooks->listDeliveries($user, $webhookId),
            $paginator->cursor,
            $paginator->limitValue(),
            static fn (WebhookDelivery $d): array => [$d->getCreatedAt(), (string) $d->getId()],
        );

        $items = [];
        foreach ($page as $delivery) {
            $items[] = $this->presenter->delivery($delivery);
        }

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
