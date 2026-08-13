<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\ApiKeyService;
use App\Platform\Presenter\ApiKeyPresenter;

/**
 * Ротация API-ключа (FR-1.5.13, POST /api-keys/{apiKeyId}/rotate).
 * Новый raw-токен отдаётся один раз (ApiKeyPresenter::withToken); старый
 * аннулируется немедленно (запросы по нему — 401). Scopes/имя/срок сохраняются.
 */
final readonly class RotateApiKeyUseCase implements PlatformUseCase
{
    public function __construct(
        private ApiKeyService $keys,
        private ApiKeyPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, string $apiKeyId): array
    {
        $result = $this->keys->rotate($user, $apiKeyId);

        return $this->presenter->withToken($result['api_key'], $result['token']);
    }
}
