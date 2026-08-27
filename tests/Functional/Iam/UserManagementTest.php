<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\User\UserDeleteController;
use App\Iam\Controller\User\UserInviteController;
use App\Iam\Controller\User\UserListController;
use App\Iam\Controller\User\UserUpdateController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\User;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\UserManagementStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.5.8/9: управление пользователями компании.
 * - invite (статус invited), смена роли, блокировка, soft-delete с маскировкой email;
 * - нельзя удалить/понизить последнего активного администратора (409);
 * - после удаления логин под старым email невозможен, email замаскирован.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class UserManagementTest extends WebTestCase
{
    private const ADMIN_EMAIL = UserManagementStory::ADMIN_EMAIL;
    private const PASSWORD = UserManagementStory::PASSWORD;

    /** @var KernelBrowser|null один клиент на тест */
    private static ?KernelBrowser $client = null;

    private Company $company;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = static::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        UserManagementStory::admin();
        $this->company = UserManagementStory::company();
        $this->token = $this->login();
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

    private function login(): string
    {
        return self::loginAs(self::ADMIN_EMAIL);
    }

    private function loginAs(string $email): string
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
     * @param array<mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = []): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            null === $data ? '' : (json_encode($data, \JSON_UNESCAPED_UNICODE) ?: ''),
        );

        return $client;
    }

    public function testAdminInvitesUserWithInvitedStatus(): void
    {
        $client = self::request('POST', UserInviteController::URL, $this->token, [
            'email' => 'invited@test.ru',
            'name' => 'Новый сотрудник',
            'role' => 'manager',
        ]);
        self::assertResponseStatusCodeSame(201);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('invited', $body['verification_status']);
        self::assertSame('manager', $body['role']);
        self::assertSame('invited@test.ru', $body['email']);
    }

    public function testInviteDefaultsToAgentRole(): void
    {
        $client = self::request('POST', UserInviteController::URL, $this->token, [
            'email' => 'new-agent@test.ru',
            'name' => 'Агент',
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('agent', $body['role']);
    }

    public function testInviteDuplicateEmailReturns409(): void
    {
        UserFactory::createOne(['email' => 'dup@test.ru', 'companyId' => null]);

        $client = self::request('POST', UserInviteController::URL, $this->token, [
            'email' => 'dup@test.ru',
            'name' => 'Дубль',
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testInviteMissingFieldsReturns422(): void
    {
        $client = self::request('POST', UserInviteController::URL, $this->token, ['email' => '', 'name' => '']);
        self::assertResponseStatusCodeSame(422);

        $client = self::request('POST', UserInviteController::URL, $this->token, ['email' => 'not-an-email', 'name' => 'x']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNonAdminCannotInvite(): void
    {
        UserManagementStory::manager();
        $token = $this->loginAs(UserManagementStory::MANAGER_EMAIL);

        $client = self::request('POST', UserInviteController::URL, $token, ['email' => 'x@test.ru', 'name' => 'X']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::client();
        $client = self::request('GET', UserListController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminChangesUserRole(): void
    {
        $target = UserFactory::createOne([
            'role' => UserRoleEnum::MANAGER,
            'companyId' => $this->company->getId(),
        ]);
        $url = str_replace('{userId}', (string) $target->getId(), UserUpdateController::URL);
        $client = self::request('PATCH', $url, $this->token, ['role' => 'agent']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('agent', $body['role']);
    }

    public function testAdminChangesUserName(): void
    {
        $target = UserFactory::createOne([
            'role' => UserRoleEnum::MANAGER,
            'companyId' => $this->company->getId(),
            'name' => 'Старое имя',
        ]);
        $url = str_replace('{userId}', (string) $target->getId(), UserUpdateController::URL);
        $client = self::request('PATCH', $url, $this->token, ['name' => 'Новое имя']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Новое имя', $body['name']);
    }

    public function testCannotDemoteLastActiveAdmin(): void
    {
        $url = str_replace('{userId}', self::currentUserId(), UserUpdateController::URL);
        $client = self::request('PATCH', $url, $this->token, ['role' => 'manager']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAdminBlocksUser(): void
    {
        $target = UserFactory::createOne([
            'role' => UserRoleEnum::MANAGER,
            'companyId' => $this->company->getId(),
        ]);
        $url = str_replace('{userId}', (string) $target->getId(), UserUpdateController::URL);
        $client = self::request('PATCH', $url, $this->token, ['status' => 'blocked']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('blocked', $body['verification_status']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find((string) $target->getId());
        self::assertNotNull($reloaded);
        self::assertSame(UserStatusEnum::BLOCKED, $reloaded->getVerificationStatus());
    }

    /**
     * Активация приглашённого админом (FR-1.5.8): PATCH status=active ведёт
     * через accept_invite, а не через unblock — иначе переход из invited
     * был бы недоступен и запрос падал бы 409.
     */
    public function testAdminActivatesInvitedUser(): void
    {
        $target = UserFactory::createOne([
            'role' => UserRoleEnum::MANAGER,
            'companyId' => $this->company->getId(),
        ]);
        $target->setVerificationStatus(UserStatusEnum::INVITED);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $url = str_replace('{userId}', (string) $target->getId(), UserUpdateController::URL);
        $client = self::request('PATCH', $url, $this->token, ['status' => 'active']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('active', $body['verification_status']);
    }

    /**
     * Повторная установка того же статуса — идемпотентный no-op, а не 409:
     * перехода block из blocked в workflow нет.
     */
    public function testSettingSameStatusIsNoop(): void
    {
        $target = UserFactory::createOne([
            'role' => UserRoleEnum::MANAGER,
            'companyId' => $this->company->getId(),
        ]);
        $target->setVerificationStatus(UserStatusEnum::BLOCKED);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $url = str_replace('{userId}', (string) $target->getId(), UserUpdateController::URL);
        $client = self::request('PATCH', $url, $this->token, ['status' => 'blocked']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('blocked', $body['verification_status']);
    }

    public function testSoftDeleteMasksEmail(): void
    {
        $target = UserFactory::createOne([
            'email' => 'victim@test.ru',
            'role' => UserRoleEnum::MANAGER,
            'companyId' => $this->company->getId(),
        ]);
        $url = str_replace('{userId}', (string) $target->getId(), UserDeleteController::URL);
        $client = self::request('DELETE', $url, $this->token, null);
        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find((string) $target->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isDeleted());
        self::assertSame(UserStatusEnum::DELETED, $reloaded->getVerificationStatus());
        self::assertSame('victim@test.ru', $reloaded->getMaskedEmail());
        self::assertStringEndsWith('@deleted.local', $reloaded->getEmail());

        // повторное удаление невозможно (переход delete из deleted отсутствует)
        $client = self::request('DELETE', $url, $this->token, null);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCannotDeleteLastActiveAdmin(): void
    {
        $url = str_replace('{userId}', self::currentUserId(), UserDeleteController::URL);
        $client = self::request('DELETE', $url, $this->token, null);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAdminListsUsers(): void
    {
        UserFactory::createOne(['role' => UserRoleEnum::AGENT, 'companyId' => $this->company->getId()]);
        $client = self::request('GET', UserListController::URL, $this->token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertCount(4, $body['items']);
    }

    public function testDeletedUserNotListed(): void
    {
        $target = UserFactory::createOne([
            'email' => 'ghost@test.ru',
            'role' => UserRoleEnum::AGENT,
            'companyId' => $this->company->getId(),
        ]);
        $url = str_replace('{userId}', (string) $target->getId(), UserDeleteController::URL);
        $client = self::request('DELETE', $url, $this->token, null);
        self::assertResponseStatusCodeSame(204);

        // после удаления юзер исчезает из GET /users (статус deleted, фильтр <> deleted)
        $client = self::request('GET', UserListController::URL, $this->token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertCount(3, $body['items']);
        foreach ($body['items'] as $item) {
            self::assertIsArray($item);
            self::assertNotSame((string) $target->getId(), $item['id']);
            self::assertNotSame(UserStatusEnum::DELETED->value, $item['verification_status']);
        }
    }

    private static function currentUserId(): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        self::assertNotNull($admin);

        return (string) $admin->getId();
    }
}
