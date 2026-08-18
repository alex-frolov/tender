<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Contract\Controller\ContractTypeListController;
use App\Document\Controller\DocumentTypeListController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\User\UserInviteController;
use App\Iam\Controller\User\UserListController;
use App\Tests\Story\UserManagementStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Механизм доступа через #[IsGranted] и Voter'ы (FR-1.5.2, см. AGENTS.md).
 *
 * Проверяем JSON-контракт ответов доступа, которые выдаёт security-компонент:
 * - не аутентифицирован                          → 401 {title: Unauthorized, code: invalid_credentials};
 * - аутентифицирован, но недостаточно прав        → 403 {title: Forbidden, code: forbidden};
 * - достаточная роль (иерархия admin ≥ manager)   → 200.
 */
final class AccessControlTest extends WebTestCase
{
    private const PASSWORD = UserManagementStory::PASSWORD;

    /** @var KernelBrowser|null один клиент на тест */
    private static ?KernelBrowser $client = null;

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
        return '12.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function loginAs(string $email): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => self::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    /**
     * @return array<mixed>
     */
    private static function request(string $method, string $url, string $token): array
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            '',
        );

        $content = $client->getResponse()->getContent();
        $decoded = json_decode((string) $content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function testUnauthenticatedReturns401JsonContract(): void
    {
        self::client();
        $body = self::request('GET', UserListController::URL, '');
        self::assertSame(401, self::client()->getResponse()->getStatusCode());
        self::assertSame('Unauthorized', $body['title'] ?? null);
        self::assertSame('invalid_credentials', $body['code'] ?? null);
    }

    public function testManagerCannotAccessAdminEndpointReturns403JsonContract(): void
    {
        self::client();
        UserManagementStory::manager();
        $token = self::loginAs(UserManagementStory::MANAGER_EMAIL);

        $body = self::request('POST', UserInviteController::URL, $token);
        self::assertSame(403, self::client()->getResponse()->getStatusCode());
        self::assertSame('Forbidden', $body['title'] ?? null);
        self::assertSame('forbidden', $body['code'] ?? null);
    }

    public function testAdminCanAccessAdminEndpoint(): void
    {
        self::client();
        UserManagementStory::admin();
        $token = self::loginAs(UserManagementStory::ADMIN_EMAIL);

        self::request('GET', UserListController::URL, $token);
        self::assertSame(200, self::client()->getResponse()->getStatusCode());
    }

    public function testDictionaryEndpointsRequireAuthentication(): void
    {
        // Справочники доступны «любому аутентифицированному» — без токена 401.
        self::client();
        self::request('GET', ContractTypeListController::URL, '');
        self::assertSame(401, self::client()->getResponse()->getStatusCode());

        self::request('GET', DocumentTypeListController::URL, '');
        self::assertSame(401, self::client()->getResponse()->getStatusCode());
    }
}
