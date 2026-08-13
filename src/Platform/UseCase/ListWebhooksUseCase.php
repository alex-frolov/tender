<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Presenter\WebhookPresenter;
use App\Platform\WebhookService;

/**
 * Список webhook-подписок компании (WH-7, GET /webhooks).
 * Ответ — список презентаций без секретов (WebhookPresenter::single).
 */
final readonly class ListWebhooksUseCase implements PlatformUseCase
{
    public function __construct(
        private WebhookService $webhooks,
        private WebhookPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user): array
    {
        $items = [];
        foreach ($this->webhooks->list($user) as $webhook) {
            $items[] = $this->presenter->single($webhook);
        }

        return ['items' => $items];
    }
}
