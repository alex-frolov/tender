<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Platform\Controller\Webhook\WebhookListController;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * E2E rate limit на реальном аутентифицированном endpoint (RL-1..6).
 *
 * - api_global (token_bucket, на IP; тестовый лимит = 3/мин) применяется к
 *   API-запросам ДО контроллера: после 3 успешных — 4-й 429;
 * - контракт ответа 429 (RL-3): JSON {title, status}, Retry-After,
 *   X-RateLimit-Limit/Remaining/Reset;
 * - лимит на IP: другой IP с тем же токеном не блокирован (RL-1);
 * - /health/* не лимитируется (RL-4).
 *
 * Каждый запрос идёт с явно заданного IP — изоляция счётчиков в общем Redis.
 */
final class RateLimitE2ETest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    protected function setUp(): void
    {
        self::$client = null;
    }

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
        return '61.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, string $ip, ?array $data = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', $ip);
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

    private static function adminToken(): string
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'rl-e2e-'.random_int(1000, 999999).'@test.ru',
        ]);

        return self::loginAs((string) $user->getEmail());
    }

    public function testAuthenticatedEndpointReturns429AfterLimit(): void
    {
        self::client();
        $token = self::adminToken();
        $ip = self::uniqueIp();

        // Тестовый лимит = 3/мин (config/packages/test/rate_limiter.yaml).
        for ($i = 0; $i < 3; ++$i) {
            $client = self::request('GET', WebhookListController::URL, $token, $ip);
            self::assertResponseStatusCodeSame(200);
        }

        // Четвёртый запрос с того же IP — 429 (RL-3) с контрактом ответа.
        $client = self::request('GET', WebhookListController::URL, $token, $ip);
        self::assertResponseStatusCodeSame(429);
        $response = $client->getResponse();
        self::assertTrue($response->headers->has('Retry-After'));
        self::assertSame('3', $response->headers->get('X-RateLimit-Limit'));
        self::assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
        self::assertTrue($response->headers->has('X-RateLimit-Reset'));

        $content = $response->getContent();
        self::assertIsString($content);
        $body = json_decode($content, true);
        self::assertIsArray($body);
        self::assertSame('Too Many Requests', $body['title'] ?? null);
        self::assertSame(429, $body['status'] ?? null);
    }

    public function testDifferentIpIsNotBlocked(): void
    {
        self::client();
        $token = self::adminToken();

        // Исчерпываем лимит одного IP.
        $blockedIp = self::uniqueIp();
        for ($i = 0; $i < 4; ++$i) {
            self::request('GET', WebhookListController::URL, $token, $blockedIp);
        }
        self::assertResponseStatusCodeSame(429);

        // Другой IP с тем же токеном работает (лимит на IP, RL-1).
        $client = self::request('GET', WebhookListController::URL, $token, self::uniqueIp());
        self::assertResponseStatusCodeSame(200);
    }

    public function testHealthEndpointNotRateLimited(): void
    {
        self::client();
        $token = self::adminToken();
        $ip = self::uniqueIp();

        // Исчерпываем лимит: 401 без токена всё равно тратит квоту IP.
        for ($i = 0; $i < 5; ++$i) {
            self::request('GET', WebhookListController::URL, $token, $ip);
        }
        self::assertResponseStatusCodeSame(429);

        // /health/live не лимитируется (RL-4).
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', $ip);
        $client->request('GET', '/health/live');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
