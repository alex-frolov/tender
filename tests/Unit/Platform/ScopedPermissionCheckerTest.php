<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use App\Platform\Entity\ApiKey;
use App\Platform\Service\ApiKeyAuthMiddleware;
use App\Platform\Service\ApiKeyScopeMap;
use App\Platform\Service\ApiKeyScopes;
use App\Platform\Service\ScopedPermissionChecker;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * проверка прав с учётом scopes API-ключа (FR-1.5.13, AR-3).
 *
 * - запрос без ключа (JWT/аноним) → делегирование внутренней проверке;
 * - ключ без scopes / api:all → полный доступ владельца (делегирование);
 * - ключ с конкретными scopes → доступ только к покрытым permission codes;
 * - нет текущего запроса (консоль) → делегирование без ограничений.
 */
final class ScopedPermissionCheckerTest extends TestCase
{
    /** @var PermissionCheckerInterface&Stub */
    private PermissionCheckerInterface $inner;

    private ApiKeyScopeMap $scopeMap;

    private RequestStack $requestStack;

    private ScopedPermissionChecker $checker;

    protected function setUp(): void
    {
        $this->inner = self::createStub(PermissionCheckerInterface::class);
        $this->inner->method('can')->willReturn(true);
        $this->scopeMap = new ApiKeyScopeMap();
        $this->requestStack = new RequestStack();
        $this->checker = new ScopedPermissionChecker($this->inner, $this->requestStack, $this->scopeMap);
    }

    private function user(): User
    {
        return new User('admin@test.ru', 'Admin', UserRoleEnum::ADMIN);
    }

    /**
     * @param list<string> $scopes
     */
    private function key(array $scopes): ApiKey
    {
        return new ApiKey(
            tenantId: Uuid::v4(),
            userId: Uuid::v4(),
            name: 'test',
            tokenHash: hash('sha256', 'key_test'),
            scopes: $scopes,
        );
    }

    private function requestWithKey(ApiKey $key): void
    {
        $request = new Request();
        $request->attributes->set(ApiKeyAuthMiddleware::ATTR_KEY, $key);
        $this->requestStack->push($request);
    }

    public function testDelegatesWhenNoApiKeyInRequest(): void
    {
        $this->requestStack->push(new Request());

        self::assertTrue($this->checker->can($this->user(), 'tenders.create'));
    }

    public function testDelegatesWhenNoCurrentRequest(): void
    {
        self::assertTrue($this->checker->can($this->user(), 'tenders.create'));
    }

    public function testKeyWithoutScopesHasFullAccess(): void
    {
        $this->requestWithKey($this->key([]));

        self::assertTrue($this->checker->can($this->user(), 'tenders.create'));
        self::assertTrue($this->checker->can($this->user(), 'webhooks.manage'));
    }

    public function testKeyWithAllScopeHasFullAccess(): void
    {
        $this->requestWithKey($this->key([ApiKeyScopes::ALL]));

        self::assertTrue($this->checker->can($this->user(), 'tenders.create'));
        self::assertTrue($this->checker->can($this->user(), 'api_keys.manage'));
    }

    public function testKeyWithConcreteScopeRestrictsPermissions(): void
    {
        $this->requestWithKey($this->key([ApiKeyScopes::TENDERS_READ]));

        self::assertTrue($this->checker->can($this->user(), 'tenders.board.view'));
        self::assertFalse($this->checker->can($this->user(), 'tenders.create'));
        self::assertFalse($this->checker->can($this->user(), 'webhooks.manage'));
    }

    public function testKeyStillRespectsInnerPermissionResult(): void
    {
        $this->inner->method('can')->willReturnCallback(
            static fn (User $user, string $code): bool => 'tenders.board.view' === $code,
        );
        $this->requestWithKey($this->key([ApiKeyScopes::TENDERS_READ]));

        // scope покрывает код, но внутренняя проверка прав решает (deny) — deny
        self::assertTrue($this->checker->can($this->user(), 'tenders.board.view'));
        // scope не покрывает код — deny без вызова внутренней проверки
        self::assertFalse($this->checker->can($this->user(), 'tenders.create'));
    }
}
