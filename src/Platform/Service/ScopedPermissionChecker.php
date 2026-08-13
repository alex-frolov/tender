<?php

declare(strict_types=1);

namespace App\Platform\Service;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use App\Platform\Entity\ApiKey;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Проверка прав с учётом scopes API-ключа (FR-1.5.13, AR-3).
 *
 * Декоратор над обычной проверкой прав (PermissionCheckService): если запрос
 * аутентифицирован по API-ключу (в request attributes есть ApiKey от
 * ApiKeyAuthMiddleware), право выдаётся только если один из scopes ключа
 * покрывает permission-code (ApiKeyScopeMap). Ключ без scopes / с api:all —
 * полный доступ владельца (обычная проверка). Все прочие запросы (JWT) —
 * делегируются без ограничений.
 *
 * Зарегистрирован алиасом на PermissionCheckerInterface (services.yaml), поэтому
 * все permission-Voter'ы (App\Security\*) получают скоуп-проверку автоматически.
 */
final readonly class ScopedPermissionChecker implements PermissionCheckerInterface
{
    public function __construct(
        private PermissionCheckerInterface $inner,
        private RequestStack $requestStack,
        private ApiKeyScopeMap $scopeMap,
    ) {
    }

    public function can(User $user, string $permissionCode): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $key = $request?->attributes->get(ApiKeyAuthMiddleware::ATTR_KEY);

        if (!$key instanceof ApiKey) {
            return $this->inner->can($user, $permissionCode);
        }

        if (!$this->scopeMap->covers($key->getScopes(), $permissionCode)) {
            return false; // действие вне scopes ключа — deny
        }

        return $this->inner->can($user, $permissionCode);
    }
}
