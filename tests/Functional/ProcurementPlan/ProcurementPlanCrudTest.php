<?php

declare(strict_types=1);

namespace App\Tests\Functional\ProcurementPlan;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\ProcurementPlan\Controller\ProcurementPlanCreateController;
use App\ProcurementPlan\Controller\ProcurementPlanListController;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * План закупок (FR-1.5.6): POST/GET /procurement-plans.
 *
 * - POST: только admin компании; создаёт план со статусом draft и items;
 * - GET: любой сотрудник компании; keyset-пагинация (items + next_cursor).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ProcurementPlanCrudTest extends WebTestCase
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
        return '66.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * @return array{admin: string, agent: string}
     */
    private static function planContext(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $admin = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'pp-admin-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'pp-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        return [
            'admin' => self::loginAs((string) $admin->getEmail()),
            'agent' => self::loginAs((string) $agent->getEmail()),
        ];
    }

    public function testCreateAndListPlan(): void
    {
        self::client();
        $ctx = self::planContext();

        $client = self::request('POST', ProcurementPlanCreateController::URL, $ctx['admin'], [
            'period' => '2026',
            'items' => [
                ['subject' => 'Стройматериалы', 'okpd2' => '23.51.1', 'volume' => 100.0, 'planned_date' => '2026-05-01', 'method' => 'auction'],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('2026', $body['period']);
        self::assertSame('draft', $body['status']);
        /** @var list<array{subject: string}> $items */
        $items = $body['items'];
        self::assertCount(1, $items);
        self::assertSame('Стройматериалы', $items[0]['subject']);

        $client = self::request('GET', ProcurementPlanListController::URL.'?limit=20', $ctx['agent']);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        self::assertCount(1, $list['items']);
        /** @var list<array{id: string}> $listedItems */
        $listedItems = $list['items'];
        self::assertSame($body['id'], $listedItems[0]['id']);
        self::assertArrayHasKey('next_cursor', $list);
    }

    public function testCreateForbiddenForAgent(): void
    {
        self::client();
        $ctx = self::planContext();

        $client = self::request('POST', ProcurementPlanCreateController::URL, $ctx['agent'], [
            'period' => '2026',
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
