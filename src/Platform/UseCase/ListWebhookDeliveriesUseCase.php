<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Presenter\WebhookPresenter;
use App\Platform\WebhookService;

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
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user, string $webhookId): array
    {
        $items = [];
        foreach ($this->webhooks->listDeliveries($user, $webhookId) as $delivery) {
            $items[] = $this->presenter->delivery($delivery);
        }

        return ['items' => $items];
    }
}
