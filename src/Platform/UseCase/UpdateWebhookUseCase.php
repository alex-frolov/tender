<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Input\UpdateWebhookInput;
use App\Platform\Presenter\WebhookPresenter;
use App\Platform\WebhookService;

/**
 * Изменение webhook-подписки (WH-7, PATCH /webhooks/{id}).
 * Обновляются только переданные поля (url/events/status); секрет — отдельный
 * эндпоинт /rotate-secret. Ответ — WebhookPresenter::single (без секрета).
 */
final readonly class UpdateWebhookUseCase implements PlatformUseCase
{
    public function __construct(
        private WebhookService $webhooks,
        private WebhookPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, string $webhookId, UpdateWebhookInput $input): array
    {
        return $this->presenter->single($this->webhooks->update($user, $webhookId, $input));
    }
}
