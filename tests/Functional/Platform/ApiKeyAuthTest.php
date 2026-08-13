<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Platform\Controller\ApiKey\ApiKeyCreateController;
use App\Platform\Controller\ApiKey\ApiKeyListController;
use App\Platform\Controller\ApiKey\ApiKeyRevokeController;
use App\Platform\Controller\Webhook\WebhookListController;
use App\Tender\Controller\TenderListController;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * аутентификация по API-ключу (AR-3, AM-1) и scopes (FR-1.5.13).
 *
 * - X-API-Key и Bearer: ключ аутентифицирует как пользователя-владельца (PAT),
 *   права ограничены scopes ключа (ScopedPermissionChecker);
 * - ключ без scopes / api:all — полный доступ владельца;
 * - отозванный/просроченный/неизвестный ключ — 401;
 * - валидный Bearer JWT остаётся приоритетным (обрабатывает AuthMiddleware).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ApiKeyAuthTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= self::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '44.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private static function request(string $method, string $url, ?string $token = null, ?string $apiKey = null, ?array $data = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $apiKey) {
            $headers['HTTP_X_API_KEY'] = $apiKey;
        } elseif (null !== $token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $client->request(
            $method,
            $url,
            [],
            [],
            $headers,
            null === $data ? '' : (json_encode($data, \JSON_UNESCAPED_UNICODE) ?: ''),
        );

        return $client;
    }

    /**
     * @return array{token: string, company: Company, user: User}
     */
    private static function adminContext(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'apikey-auth-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['token' => self::loginAs((string) $user->getEmail()), 'company' => $company, 'user' => $user];
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
            json_encode(['email' => $email, 'password' => UserFactory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    /**
     * Выпуск ключа через API (JWT-токен админа) и возврат raw-токена.
     *
     * @param list<string> $scopes
     */
    private static function createKey(string $token, array $scopes): string
    {
        $client = self::request('POST', ApiKeyCreateController::URL, $token, null, [
            'name' => 'auth-test',
            'scopes' => $scopes,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['token']);

        return $body['token'];
    }

    public function testApiKeyAuthenticatesAndScopesRestrict(): void
    {
        self::client();
        $ctx = self::adminContext();
        $raw = self::createKey($ctx['token'], ['api:tenders:read']);

        // scope api:tenders:read → доска тендеров доступна (tenders.board.view).
        $client = self::request('GET', TenderListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(200);

        // webhooks.manage вне scopes → 403.
        $client = self::request('GET', WebhookListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(403);

        // api_keys.manage вне scopes → 403.
        $client = self::request('GET', ApiKeyListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(403);
    }

    public function testApiKeyWithWebhookScopeCanManageWebhooks(): void
    {
        self::client();
        $ctx = self::adminContext();
        $raw = self::createKey($ctx['token'], ['api:webhooks:manage']);

        $client = self::request('GET', WebhookListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(200);

        // webhooks.manage покрыт, но api_keys.manage — нет → 403.
        $client = self::request('GET', ApiKeyListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(403);
    }

    public function testApiKeyWithKeysScopeCanManageKeys(): void
    {
        self::client();
        $ctx = self::adminContext();
        $raw = self::createKey($ctx['token'], ['api:keys:manage']);

        $client = self::request('GET', ApiKeyListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('GET', WebhookListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(403);
    }

    public function testApiKeyWithoutScopesHasFullAccess(): void
    {
        self::client();
        $ctx = self::adminContext();
        $raw = self::createKey($ctx['token'], []);

        $client = self::request('GET', WebhookListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('GET', ApiKeyListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('GET', TenderListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(200);
    }

    public function testApiKeyWithAllScopeHasFullAccess(): void
    {
        self::client();
        $ctx = self::adminContext();
        $raw = self::createKey($ctx['token'], ['api:all']);

        $client = self::request('GET', WebhookListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('GET', ApiKeyListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(200);
    }

    public function testApiKeyViaBearerHeader(): void
    {
        self::client();
        $ctx = self::adminContext();
        $raw = self::createKey($ctx['token'], ['api:webhooks:manage']);

        // openapi bearerAuth: «OAuth2 access token / PAT / API-ключ (Bearer)».
        $client = self::request('GET', WebhookListController::URL, $raw);
        self::assertResponseStatusCodeSame(200);
    }

    public function testRevokedApiKeyReturns401(): void
    {
        self::client();
        $ctx = self::adminContext();
        $raw = self::createKey($ctx['token'], ['api:webhooks:manage']);

        // Отзыв ключа через JWT: нужен id ключа.
        $client = self::request('GET', ApiKeyListController::URL, $ctx['token']);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        self::assertIsArray($list['items'][0]);
        self::assertIsString($list['items'][0]['id']);
        $keyId = $list['items'][0]['id'];

        $client = self::request('DELETE', str_replace('{apiKeyId}', $keyId, ApiKeyRevokeController::URL), $ctx['token']);
        self::assertResponseStatusCodeSame(204);

        // Отозванный ключ → аноним → 401.
        $client = self::request('GET', WebhookListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(401);
    }

    public function testExpiredApiKeyReturns401(): void
    {
        self::client();
        $ctx = self::adminContext();
        $raw = 'key_expired_'.bin2hex(random_bytes(8));
        ApiKeyFactory::createOne([
            'tenantId' => $ctx['company']->getId(),
            'userId' => $ctx['user']->getId(),
            'tokenHash' => hash('sha256', $raw),
            'expiresAt' => new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC')),
        ]);

        $client = self::request('GET', WebhookListController::URL, null, $raw);
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownApiKeyReturns401(): void
    {
        self::client();
        self::adminContext();

        $client = self::request('GET', WebhookListController::URL, null, 'key_does_not_exist');
        self::assertResponseStatusCodeSame(401);
    }

    public function testJwtStillAuthenticatesNormally(): void
    {
        self::client();
        $ctx = self::adminContext();

        // Валидный JWT — полный доступ владельца (scopes не применяются).
        $client = self::request('GET', WebhookListController::URL, $ctx['token']);
        self::assertResponseStatusCodeSame(200);
    }
}
