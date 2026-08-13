<?php

declare(strict_types=1);

namespace App\Iam\Service;

use Symfony\Component\Uid\Uuid;

/**
 * Контекст аутентифицированного пользователя (из JWT или сессии).
 * Прокидывается в AuthMiddleware → request attributes.
 */
final readonly class AuthContext
{
    public function __construct(
        public Uuid $userId,
        public ?Uuid $companyId,
        public string $role,
        public ?string $jti,
    ) {
    }
}
