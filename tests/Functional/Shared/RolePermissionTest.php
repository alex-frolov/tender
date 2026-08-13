<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\Permission\PermissionListController;
use App\Iam\Controller\Permission\RolePermissionGetController;
use App\Iam\Controller\Permission\RolePermissionUpdateController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckService;
use App\Iam\Service\RolePermissionCache;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.5.10/1.5.15: каталог permissions + role_permissions + проверка прав.
 * - каталог и наборы ролей manager/agent читает суперадмин;
 * - PUT задаёт набор роли, изменение применяется немедленно (PermissionCheckService
 *   читает кэш наборов; RolePermissionService инвалидирует его при обновлении);
 * - admin — полный набор; нет записи → default-матрица; deny by default;
 * - аудит изменений набора.
 */
final class RolePermissionTest extends WebTestCase
{
    private const PLATFORM_EMAIL = 'sa-perm@test.ru';
    private const PASSWORD = 'secret123';

    /** @var KernelBrowser|null один клиент на тест */
    private static ?KernelBrowser $client = null;

    protected function setUp(): void
    {
        // кэш наборов общий для Redis — чистим перед каждым тестом, чтобы
        // stale-значения из одной транзакции не протекали в следующую
        self::client();
        $cache = self::getContainer()->get(RolePermissionCache::class);
        self::assertInstanceOf(RolePermissionCache::class, $cache);
        $cache->clear();
    }

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= static::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '11.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            throw new \LogicException('Cannot encode JSON');
        }

        return $json;
    }

    private static function platformAdmin(): User
    {
        return UserFactory::createOne([
            'email' => self::PLATFORM_EMAIL,
            'name' => 'Суперадмин',
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'password' => self::PASSWORD,
        ]);
    }

    private static function login(string $email = self::PLATFORM_EMAIL): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            self::json(['email' => $email, 'password' => self::PASSWORD]),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    /**
     * @param array<mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private static function request(string $method, string $url, string $token, ?array $body = null): array
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            null === $body ? '' : self::json($body),
        );

        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        if (!\is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testPermissionCatalogReturnsFullList(): void
    {
        self::client();
        self::platformAdmin();
        $token = self::login();

        $body = self::request('GET', PermissionListController::URL, $token);
        self::assertSame(200, self::client()->getResponse()->getStatusCode());
        /** @var array<array<string, mixed>> $items */
        $items = $body['items'] ?? [];
        self::assertNotEmpty($items);

        $codes = array_column($items, 'code');
        self::assertContains('tenders.create', $codes);
        self::assertContains('tenders.board.view', $codes);
        self::assertNotContains('tenders.rating', $codes);
    }

    public function testGetRoleSetsReturnsManagerAndAgentDefaults(): void
    {
        self::client();
        self::platformAdmin();
        $token = self::login();

        $body = self::request('GET', RolePermissionGetController::URL, $token);
        self::assertSame(200, self::client()->getResponse()->getStatusCode());
        /** @var array<string, array<array<string, mixed>>> $roles */
        $roles = $body['roles'] ?? [];
        self::assertArrayHasKey('manager', $roles);
        self::assertArrayHasKey('agent', $roles);

        $byCode = [];
        foreach ($roles['manager'] as $row) {
            $code = $row['permission_code'] ?? '';
            if (\is_string($code)) {
                $byCode[$code] = $row;
            }
        }

        // default-матрица: manager создаёт тендеры, agent — нет
        self::assertTrue((bool) $byCode['tenders.create']['enabled']);
        self::assertTrue((bool) $byCode['tenders.create']['is_default']);
        self::assertFalse((bool) $byCode['users.manage']['enabled']);
    }

    public function testUpdateRoleAppliesImmediately(): void
    {
        self::client();
        self::platformAdmin();
        $token = self::login();

        $manager = UserFactory::createOne([
            'role' => UserRoleEnum::MANAGER,
            'companyId' => CompanyFactory::createOne()->getId(),
            'password' => self::PASSWORD,
        ]);

        $checker = self::getContainer()->get(PermissionCheckService::class);
        self::assertInstanceOf(PermissionCheckService::class, $checker);
        // до изменения — default: manager может создавать тендеры
        self::assertTrue($checker->can($manager, 'tenders.create'));

        $body = self::request('PUT', RolePermissionUpdateController::URL, $token, [
            'role' => 'manager',
            'permissions' => ['tenders.create' => false],
        ]);
        self::assertSame(200, self::client()->getResponse()->getStatusCode());
        self::assertSame('manager', $body['role']);

        // немедленно: набор изменился для всех пользователей роли без перелогина
        self::assertFalse($checker->can($manager, 'tenders.create'));
        // прочие права роли не затронуты
        self::assertTrue($checker->can($manager, 'tenders.board.view'));
        // admin — полный набор, изменение роли на него не влияет
        $admin = UserFactory::createOne(['role' => UserRoleEnum::ADMIN]);
        self::assertTrue($checker->can($admin, 'tenders.create'));
        self::assertTrue($checker->can($admin, 'users.manage'));
    }

    public function testAgentCannotCreateTenderByDefault(): void
    {
        self::client();
        self::platformAdmin();
        $token = self::login();

        $agent = UserFactory::createOne(['role' => UserRoleEnum::AGENT]);
        $checker = self::getContainer()->get(PermissionCheckService::class);
        self::assertInstanceOf(PermissionCheckService::class, $checker);

        self::assertFalse($checker->can($agent, 'tenders.create'));
        self::assertTrue($checker->can($agent, 'tenders.board.view'));
        self::assertTrue($checker->can($agent, 'tenders.qa'));
    }

    public function testUpdateRejectsUnknownCode422(): void
    {
        self::client();
        self::platformAdmin();
        $token = self::login();

        self::request('PUT', RolePermissionUpdateController::URL, $token, [
            'role' => 'manager',
            'permissions' => ['does.not.exist' => false],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateRejectsNonBooleanValue422(): void
    {
        self::client();
        self::platformAdmin();
        $token = self::login();

        self::request('PUT', RolePermissionUpdateController::URL, $token, [
            'role' => 'manager',
            'permissions' => ['tenders.create' => 'yes'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateRejectsInvalidRole422(): void
    {
        self::client();
        self::platformAdmin();
        $token = self::login();

        self::request('PUT', RolePermissionUpdateController::URL, $token, [
            'role' => 'admin',
            'permissions' => ['tenders.create' => false],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNonPlatformActorForbidden(): void
    {
        self::client();
        $admin = UserFactory::createOne([
            'role' => UserRoleEnum::ADMIN,
            'email' => 'admin-perm@test.ru',
            'password' => self::PASSWORD,
        ]);
        $token = self::login('admin-perm@test.ru');

        self::request('GET', RolePermissionGetController::URL, $token);
        self::assertSame(403, self::client()->getResponse()->getStatusCode());
        self::assertFalse($admin->isPlatformAdmin());
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::client();
        self::request('GET', PermissionListController::URL, '');
        self::assertSame(401, self::client()->getResponse()->getStatusCode());
    }
}
