<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Presenter\WebhookPresenter;
use App\Platform\WebhookService;

/**
 * Ротация секрета webhook-подписки (WH-7, POST /webhooks/{id}/rotate-secret).
 * Новый секрет отдаётся один раз (WebhookPresenter::withSecret); подписи HMAC
 * начинают считаться новым секретом немедленно (WH-3).
 */
final readonly class RotateWebhookSecretUseCase implements PlatformUseCase
{
    public function __construct(
        private WebhookService $webhooks,
        private WebhookPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, string $webhookId): array
    {
        $result = $this->webhooks->rotateSecret($user, $webhookId);

        return $this->presenter->withSecret($result['webhook'], $result['secret']);
    }
}
