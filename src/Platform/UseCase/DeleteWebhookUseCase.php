<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\WebhookService;

/**
 * Удаление webhook-подписки (WH-7, DELETE /webhooks/{id}).
 * Доставки удаляются каскадно; ответ — 204 (пустое тело).
 */
final readonly class DeleteWebhookUseCase implements PlatformUseCase
{
    public function __construct(private WebhookService $webhooks)
    {
    }

    /**
     * Возвращает id удалённой подписки (контроллер отвечает 204).
     */
    public function execute(User $user, string $webhookId): string
    {
        return $this->webhooks->delete($user, $webhookId);
    }
}
