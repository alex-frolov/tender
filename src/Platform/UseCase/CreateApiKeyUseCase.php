<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\ApiKeyService;
use App\Platform\Input\CreateApiKeyInput;
use App\Platform\Presenter\ApiKeyPresenter;

/**
 * Выпуск API-ключа (FR-1.5.13, POST /api-keys).
 * Вход — валидированный CreateApiKeyInput (форма ApiKeyCreateType),
 * оркестрация — ApiKeyService::create, ответ — ApiKeyPresenter::withToken
 * (raw-токен отдаётся один раз, AR-3). Доступ — право api_keys.manage.
 */
final readonly class CreateApiKeyUseCase implements PlatformUseCase
{
    public function __construct(
        private ApiKeyService $keys,
        private ApiKeyPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> представление ключа + одноразовый token
     */
    public function execute(User $user, CreateApiKeyInput $input): array
    {
        $result = $this->keys->create($user, $input);

        return $this->presenter->withToken($result['api_key'], $result['token']);
    }
}
