<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Platform\Service\ApiKeyScopeMap;
use App\Platform\Service\ApiKeyScopes;
use PHPUnit\Framework\TestCase;

/**
 * маппинг scopes API-ключа → permission codes (FR-1.5.13).
 *
 * - пустой набор / api:all — покрывает всё (полный доступ владельца);
 * - конкретный scope покрывает только свои permission codes;
 * - неизвестный permission/scope не покрывается;
 * - каталог допустимых scopes корректен (валидация при выпуске ключа).
 */
final class ApiKeyScopeMapTest extends TestCase
{
    public function testEmptyScopesCoversEverything(): void
    {
        $map = new ApiKeyScopeMap();

        self::assertTrue($map->covers([], 'tenders.create'));
        self::assertTrue($map->covers([], 'webhooks.manage'));
    }

    public function testAllScopeCoversEverything(): void
    {
        $map = new ApiKeyScopeMap();

        self::assertTrue($map->covers([ApiKeyScopes::ALL], 'tenders.create'));
        self::assertTrue($map->covers([ApiKeyScopes::ALL], 'api_keys.manage'));
    }

    public function testConcreteScopeCoversOnlyItsPermissions(): void
    {
        $map = new ApiKeyScopeMap();

        self::assertTrue($map->covers([ApiKeyScopes::TENDERS_READ], 'tenders.board.view'));
        self::assertFalse($map->covers([ApiKeyScopes::TENDERS_READ], 'tenders.create'));
        self::assertFalse($map->covers([ApiKeyScopes::TENDERS_READ], 'webhooks.manage'));
    }

    public function testWriteScopeCoversAllWritePermissions(): void
    {
        $map = new ApiKeyScopeMap();

        foreach (['tenders.create', 'tenders.publish', 'tenders.update', 'tenders.withdraw', 'tenders.cancel'] as $code) {
            self::assertTrue($map->covers([ApiKeyScopes::TENDERS_WRITE], $code), $code);
        }
    }

    public function testUnknownPermissionNotCovered(): void
    {
        $map = new ApiKeyScopeMap();

        self::assertFalse($map->covers([ApiKeyScopes::TENDERS_READ], 'some.unknown.permission'));
    }

    public function testUnknownScopeNotCovered(): void
    {
        $map = new ApiKeyScopeMap();

        self::assertFalse($map->covers(['api:unknown:scope'], 'tenders.board.view'));
    }

    public function testCatalogIsValidAndContainsAll(): void
    {
        self::assertTrue(ApiKeyScopes::isValid(ApiKeyScopes::catalog()));
        self::assertTrue(ApiKeyScopes::isValid([ApiKeyScopes::ALL, ApiKeyScopes::PROFILE]));
        self::assertFalse(ApiKeyScopes::isValid(['api:not-a-scope']));
        self::assertFalse(ApiKeyScopes::isValid(['']));
    }

    public function testAllCatalogScopesHaveMapping(): void
    {
        $map = new ApiKeyScopeMap();

        foreach (ApiKeyScopes::catalog() as $scope) {
            self::assertArrayHasKey($scope, ApiKeyScopeMap::PERMISSION_MAP, $scope);
        }
    }
}
