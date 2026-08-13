<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Input\CreateWebhookInput;
use App\Platform\Presenter\WebhookPresenter;
use App\Platform\WebhookService;

/**
 * Создание webhook-подписки (WH-7, POST /webhooks).
 * Вход — валидированный CreateWebhookInput (форма WebhookCreateType),
 * оркестрация — WebhookService::create, ответ — WebhookPresenter::withSecret
 * (секрет отдаётся один раз, WH-3). Доступ — право webhooks.manage (WebhookVoter).
 */
final readonly class CreateWebhookUseCase implements PlatformUseCase
{
    public function __construct(
        private WebhookService $webhooks,
        private WebhookPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация подписки + одноразовый secret
     */
    public function execute(User $user, CreateWebhookInput $input): array
    {
        $result = $this->webhooks->create($user, $input);

        return $this->presenter->withSecret($result['webhook'], $result['secret']);
    }
}
