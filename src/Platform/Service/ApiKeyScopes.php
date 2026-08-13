<?php

declare(strict_types=1);

namespace App\Platform\Service;

/**
 * Каталог scopes API-ключей (FR-1.5.13, AR-3, AM-1).
 *
 * Scope — право ключа на группу действий (permission codes из domain/permissions.md).
 * Ключ с scopes сужает права пользователя-владельца при аутентификации по ключу
 * (ScopedPermissionChecker). Пустой scopes / api:all — полный доступ владельца.
 *
 * Формат: `api:{модуль}:{операция}`. Каталог используется при выпуске ключа
 * (валидация) и при проверке доступа (ApiKeyScopeMap::covers).
 *
 * @see ApiKeyScopeMap
 */
final readonly class ApiKeyScopes
{
    /** Полный доступ (default): все действия, доступные пользователю-владельцу. */
    public const string ALL = 'api:all';

    public const string PROFILE = 'api:profile';
    public const string TENDERS_READ = 'api:tenders:read';
    public const string TENDERS_WRITE = 'api:tenders:write';
    public const string TENDERS_DOCS = 'api:tenders:docs';
    public const string TENDERS_QA = 'api:tenders:qa';
    public const string TENDERS_RATE = 'api:tenders:rate';
    public const string BIDS_PREPARE = 'api:bids:prepare';
    public const string BIDS_WRITE = 'api:bids:write';
    public const string BIDS_QUALIFY = 'api:bids:qualify';
    public const string AUCTIONS_BID = 'api:auctions:bid';
    public const string AUCTIONS_CONTROL = 'api:auctions:control';
    public const string CONTRACTS_WRITE = 'api:contracts:write';
    public const string CLAIMS_WRITE = 'api:claims:write';
    public const string EXECUTION_WRITE = 'api:execution:write';
    public const string ANALYTICS_READ = 'api:analytics:read';
    public const string EXPORTS_EXPORT = 'api:exports:export';
    public const string WEBHOOKS_MANAGE = 'api:webhooks:manage';
    public const string KEYS_MANAGE = 'api:keys:manage';
    public const string USERS_MANAGE = 'api:users:manage';
    public const string PLATFORM_ADMIN = 'api:platform:admin';

    /**
     * Допустимые scope-коды (используется при выпуске ключа).
     *
     * @return list<string>
     */
    public static function catalog(): array
    {
        return [
            self::ALL,
            self::PROFILE,
            self::TENDERS_READ,
            self::TENDERS_WRITE,
            self::TENDERS_DOCS,
            self::TENDERS_QA,
            self::TENDERS_RATE,
            self::BIDS_PREPARE,
            self::BIDS_WRITE,
            self::BIDS_QUALIFY,
            self::AUCTIONS_BID,
            self::AUCTIONS_CONTROL,
            self::CONTRACTS_WRITE,
            self::CLAIMS_WRITE,
            self::EXECUTION_WRITE,
            self::ANALYTICS_READ,
            self::EXPORTS_EXPORT,
            self::WEBHOOKS_MANAGE,
            self::KEYS_MANAGE,
            self::USERS_MANAGE,
            self::PLATFORM_ADMIN,
        ];
    }

    /**
     * @param list<string> $scopes
     */
    public static function isValid(array $scopes): bool
    {
        $catalog = self::catalog();
        foreach ($scopes as $scope) {
            if (!\in_array($scope, $catalog, true)) {
                return false;
            }
        }

        return true;
    }
}
