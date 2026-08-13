<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\ApiKeyService;
use App\Platform\Presenter\ApiKeyPresenter;

/**
 * Отзыв API-ключа (FR-1.5.13, DELETE /api-keys/{apiKeyId}).
 * Оркестрация — ApiKeyService::revoke; после отзыва аутентификация по ключу
 * невозможна (401). Чужой ключ — 404 (tenant-изоляция в сервисе).
 */
final readonly class RevokeApiKeyUseCase implements PlatformUseCase
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
        return $this->presenter->single($this->keys->revoke($user, $apiKeyId));
    }
}
