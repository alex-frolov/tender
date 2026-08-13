<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Platform\Controller\ApiKey\ApiKeyCreateController;
use App\Platform\Controller\ApiKey\ApiKeyListController;
use App\Platform\Controller\ApiKey\ApiKeyRevokeController;
use App\Platform\Controller\ApiKey\ApiKeyRotateController;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API-ключи — CRUD через API (FR-1.5.13, AR-3).
 *
 * - Создание (POST /api-keys): token отдаётся один раз; scopes из каталога;
 * - Список (GET /api-keys): ключи без raw-токенов/hash;
 * - Ротация (POST /api-keys/{id}/rotate): новый token один раз;
 * - Отзыв (DELETE /api-keys/{id}): 204;
 * - Права (FR-1.5.10): api_keys.manage — admin; manager/agent — 403;
 *   401 без токена; чужой ключ — 404; невалидные scopes — 422.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ApiKeyCrudTest extends WebTestCase
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
        return '33.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = null): KernelBrowser
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
     * @return array{token: string, company: object}
     */
    private static function adminToken(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'apikey-admin-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['token' => self::loginAs((string) $user->getEmail()), 'company' => $company];
    }

    public function testFullLifecycleCreateListRotateRevoke(): void
    {
        self::client();
        ['token' => $token] = self::adminToken();

        // Создание: token отдаётся один раз, scopes сохраняются.
        $client = self::request('POST', ApiKeyCreateController::URL, $token, [
            'name' => 'CI integration',
            'scopes' => ['api:tenders:read', 'api:webhooks:manage'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $keyId = $body['id'];
        self::assertIsString($keyId);
        self::assertSame('CI integration', $body['name']);
        self::assertSame(['api:tenders:read', 'api:webhooks:manage'], $body['scopes']);
        self::assertNull($body['expires_at']);
        self::assertNull($body['revoked_at']);
        self::assertIsString($body['token']);
        self::assertStringStartsWith('key_', $body['token']);
        self::assertArrayNotHasKey('token_hash', $body);

        // Список: ключи без raw-токенов/hash.
        $client = self::request('GET', ApiKeyListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        $ids = array_column($list['items'], 'id');
        self::assertContains($keyId, $ids);
        $first = $list['items'][0];
        self::assertIsArray($first);
        self::assertArrayNotHasKey('token', $first);
        self::assertArrayNotHasKey('token_hash', $first);

        // Ротация: новый token один раз.
        $client = self::request('POST', str_replace('{apiKeyId}', $keyId, ApiKeyRotateController::URL), $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($keyId, $body['id']);
        self::assertIsString($body['token']);
        self::assertStringStartsWith('key_', $body['token']);

        // Отзыв → 204.
        $client = self::request('DELETE', str_replace('{apiKeyId}', $keyId, ApiKeyRevokeController::URL), $token);
        self::assertResponseStatusCodeSame(204);

        // После отзыва ключ в списке с revoked_at.
        $client = self::request('GET', ApiKeyListController::URL, $token);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        $revoked = array_filter($list['items'], static fn (mixed $item): bool => \is_array($item) && $item['id'] === $keyId);
        self::assertCount(1, $revoked);
        $revokedKey = array_values($revoked)[0];
        self::assertIsArray($revokedKey);
        self::assertNotNull($revokedKey['revoked_at']);
    }

    public function testCreateWithExpiresAtAndEmptyScopes(): void
    {
        self::client();
        ['token' => $token] = self::adminToken();

        $client = self::request('POST', ApiKeyCreateController::URL, $token, [
            'name' => 'Short-lived',
            'expires_at' => '2030-01-01T00:00:00+00:00',
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame([], $body['scopes']);
        self::assertSame('2030-01-01T00:00:00Z', $body['expires_at']);
    }

    public function testCreateRejectsInvalidScope(): void
    {
        self::client();
        ['token' => $token] = self::adminToken();

        $client = self::request('POST', ApiKeyCreateController::URL, $token, [
            'name' => 'Bad scopes',
            'scopes' => ['api:unknown:scope'],
        ]);
        self::assertResponseStatusCodeSame(422);

        $client = self::request('POST', ApiKeyCreateController::URL, $token, ['name' => '']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testManagerAndAgentCannotManageKeysReturns403(): void
    {
        self::client();
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();

        $manager = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::MANAGER,
            'email' => 'apikey-manager-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'apikey-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $managerToken = self::loginAs((string) $manager->getEmail());
        $agentToken = self::loginAs((string) $agent->getEmail());

        // manager и agent — api_keys.manage отключено по умолчанию (FR-1.5.10).
        $client = self::request('POST', ApiKeyCreateController::URL, $managerToken, [
            'name' => 'nope',
        ]);
        self::assertResponseStatusCodeSame(403);

        $client = self::request('GET', ApiKeyListController::URL, $agentToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::client();
        $client = self::request('GET', ApiKeyListController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testForeignApiKeyReturns404(): void
    {
        self::client();
        $other = ApiKeyFactory::createOne();
        ['token' => $token] = self::adminToken();

        $client = self::request('DELETE', str_replace('{apiKeyId}', (string) $other->getId(), ApiKeyRevokeController::URL), $token);
        self::assertResponseStatusCodeSame(404);

        $client = self::request('POST', str_replace('{apiKeyId}', (string) $other->getId(), ApiKeyRotateController::URL), $token);
        self::assertResponseStatusCodeSame(404);
    }
}
