<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\ApiKeyService;
use App\Platform\Presenter\ApiKeyPresenter;

/**
 * Список API-ключей компании (FR-1.5.13, GET /api-keys).
 * Вход — действующий пользователь, оркестрация — ApiKeyService::list,
 * ответ — ApiKeyPresenter::single (без raw-токенов и hash, AR-3).
 */
final readonly class ListApiKeysUseCase implements PlatformUseCase
{
    public function __construct(
        private ApiKeyService $keys,
        private ApiKeyPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user): array
    {
        $items = [];
        foreach ($this->keys->list($user) as $key) {
            $items[] = $this->presenter->single($key);
        }

        return ['items' => $items];
    }
}
