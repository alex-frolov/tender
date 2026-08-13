<?php

declare(strict_types=1);

namespace App\Platform\Service;

/**
 * Маппинг scopes API-ключа → permission codes (FR-1.5.13, FR-1.5.15).
 *
 * Проверка доступа при аутентификации по ключу: ключ разрешает пользователю
 * только те permission-коды, которые покрыты его scopes (код группы
 * domain/permissions.md, соответствие — таблица ниже). Ключ с scopes = [api:all]
 * или без scopes ([]) даёт полный доступ пользователя (ScopedPermissionChecker
 * делегирует обычной проверке прав).
 */
final readonly class ApiKeyScopeMap
{
    /**
     * Scope → список permission codes (domain/permissions.md).
     *
     * @var array<string, list<string>>
     */
    public const array PERMISSION_MAP = [
        // Полный доступ: covers() возвращает true раньше, чем проходит по карте.
        ApiKeyScopes::ALL => [],
        ApiKeyScopes::PROFILE => ['profile.view', 'profile.update'],
        ApiKeyScopes::TENDERS_READ => ['tenders.board.view'],
        ApiKeyScopes::TENDERS_WRITE => ['tenders.create', 'tenders.publish', 'tenders.update', 'tenders.withdraw', 'tenders.cancel'],
        ApiKeyScopes::TENDERS_DOCS => ['tenders.manage_docs'],
        ApiKeyScopes::TENDERS_QA => ['tenders.qa'],
        ApiKeyScopes::TENDERS_RATE => ['tenders.rate'],
        ApiKeyScopes::BIDS_PREPARE => ['bids.draft_prepare'],
        ApiKeyScopes::BIDS_WRITE => ['bids.submit', 'bids.withdraw'],
        ApiKeyScopes::BIDS_QUALIFY => ['bids.qualify'],
        ApiKeyScopes::AUCTIONS_BID => ['auction.bid'],
        ApiKeyScopes::AUCTIONS_CONTROL => ['auction.control', 'auction.choose_winner'],
        ApiKeyScopes::CONTRACTS_WRITE => ['contracts.create', 'contracts.sign', 'contracts.scan_upload'],
        ApiKeyScopes::CLAIMS_WRITE => ['claims.manage', 'claims.respond'],
        ApiKeyScopes::EXECUTION_WRITE => ['execution.manage'],
        ApiKeyScopes::ANALYTICS_READ => ['dashboard.view'],
        ApiKeyScopes::EXPORTS_EXPORT => ['exports.export'],
        ApiKeyScopes::WEBHOOKS_MANAGE => ['webhooks.manage'],
        ApiKeyScopes::KEYS_MANAGE => ['api_keys.manage'],
        ApiKeyScopes::USERS_MANAGE => ['users.manage', 'org.settings'],
        ApiKeyScopes::PLATFORM_ADMIN => ['platform.timezone.manage'],
    ];

    /**
     * Покрывает ли набор scopes permission-code.
     *
     * @param list<string> $scopes
     */
    public function covers(array $scopes, string $permissionCode): bool
    {
        if ([] === $scopes || \in_array(ApiKeyScopes::ALL, $scopes, true)) {
            return true;
        }

        foreach ($scopes as $scope) {
            if (\in_array($permissionCode, self::PERMISSION_MAP[$scope] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
