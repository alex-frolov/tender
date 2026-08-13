<?php

declare(strict_types=1);

namespace App\Tests\Functional\Analytics;

use App\Analytics\Controller\Dashboard\DashboardController;
use App\Analytics\Controller\Dashboard\TenderStatsController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Service\RolePermissionCache;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * дашборд и статистика через API (AM-13).
 *
 * - GET /dashboard: 200 со счётчиками и дедлайнами; невалидный period → 422;
 * - GET /stats/tenders: 200 с агрегатами; невалидный dimension → 422;
 * - доступ: dashboard.view (common) — admin/manager/agent 200, 401 без токена.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 * Кэш наборов прав очищается: Redis общий с dev, кэш мог быть собран до
 * добавления dashboard.view (миграция 20260812120000).
 */
final class DashboardTest extends WebTestCase
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
        if (null === self::$client) {
            self::$client = self::createClient();
            // Кэш наборов прав очищается при (пере)создании клиента: Redis общий
            // с dev, кэш мог быть собран до добавления dashboard.view
            // (миграция 20260812120000) — тест должен видеть свежую матрицу.
            static::getContainer()->get(RolePermissionCache::class)->clear();
        }

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '41.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, string> $query
     */
    private static function request(string $method, string $url, string $token, array $query = []): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            $query,
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            '',
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
     * @return array<mixed>
     */
    private static function json(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    public function testDashboardReturnsCountersAndDeadlines(): void
    {
        self::client();
        $token = self::adminToken();

        $client = self::request('GET', DashboardController::URL, $token);
        self::assertResponseStatusCodeSame(200);

        $body = self::json($client);
        self::assertArrayHasKey('active_tenders', $body);
        self::assertArrayHasKey('my_bids', $body);
        self::assertArrayHasKey('my_contracts', $body);
        self::assertIsArray($body['upcoming_deadlines']);
        self::assertIsInt($body['active_tenders']);
    }

    public function testDashboardRejectsInvalidPeriod(): void
    {
        self::client();
        $token = self::adminToken();

        $client = self::request('GET', DashboardController::URL, $token, ['period' => 'year']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTenderStatsByRegion(): void
    {
        self::client();
        $token = self::adminToken();

        $client = self::request('GET', TenderStatsController::URL, $token, ['dimension' => 'region']);
        self::assertResponseStatusCodeSame(200);

        $body = self::json($client);
        self::assertArrayHasKey('items', $body);
        self::assertIsArray($body['items']);
    }

    public function testTenderStatsRejectsInvalidDimension(): void
    {
        self::client();
        $token = self::adminToken();

        $client = self::request('GET', TenderStatsController::URL, $token, ['dimension' => 'bogus']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::client();
        $client = self::request('GET', DashboardController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testManagerAndAgentCanViewDashboard(): void
    {
        self::client();
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::BOTH])->approved()->create();

        $manager = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::MANAGER,
            'email' => 'dash-manager-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'dash-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        $managerToken = self::loginAs((string) $manager->getEmail());
        $agentToken = self::loginAs((string) $agent->getEmail());

        // dashboard.view — common: включено по умолчанию для manager/agent.
        $client = self::request('GET', DashboardController::URL, $managerToken);
        self::assertResponseStatusCodeSame(200);
        $client = self::request('GET', TenderStatsController::URL, $agentToken, ['dimension' => 'period']);
        self::assertResponseStatusCodeSame(200);
    }

    private static function adminToken(): string
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'dash-admin-'.random_int(1000, 999999).'@test.ru',
        ]);

        return self::loginAs((string) $user->getEmail());
    }
}
