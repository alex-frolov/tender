<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\Company\CompanySearchController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Поиск контрагента (GET /companies/search).
 *
 * - находит по подстроке названия и по ИНН, без учёта регистра;
 * - неподтверждённые компании не выдаются: это состояние модерации;
 * - краткая карточка — без реквизитов (адрес, контакты, КПП, ОГРН);
 * - пустой или односимвольный запрос → 422 (это не реестр площадки);
 * - 401 без токена.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class CompanySearchTest extends WebTestCase
{
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
        return '74.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function request(string $url, ?string $token): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $client->request('GET', $url, [], [], $headers);

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
     * @return list<array<string, mixed>>
     */
    private static function itemsOf(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);

        /** @var list<array<string, mixed>> $items */
        $items = $body['items'];

        return $items;
    }

    /**
     * Контекст: подтверждённый поставщик с узнаваемым названием и ИНН,
     * неподтверждённая компания с тем же маркером и токен постороннего актора.
     *
     * @return array{token: string, marker: string, activeId: string, pendingId: string, inn: string}
     */
    private static function context(): array
    {
        $marker = 'Поиск'.random_int(100000, 999999);
        $inn = (string) random_int(7000000000, 7999999999);

        $active = CompanyFactory::new([
            'legalName' => 'ООО '.$marker,
            'inn' => $inn,
            'type' => CompanyTypeEnum::SUPPLIER,
        ])->approved()->create();

        // Компания на модерации: в выдачу попадать не должна.
        $pending = CompanyFactory::createOne([
            'legalName' => 'ЗАО '.$marker,
            'type' => CompanyTypeEnum::SUPPLIER,
        ]);

        $viewerCompany = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $viewer = UserFactory::createOne([
            'companyId' => $viewerCompany->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'search-viewer-'.random_int(1000, 999999).'@test.ru',
        ]);

        return [
            'token' => self::loginAs((string) $viewer->getEmail()),
            'marker' => $marker,
            'activeId' => (string) $active->getId(),
            'pendingId' => (string) $pending->getId(),
            'inn' => $inn,
        ];
    }

    public function testFindsApprovedCompanyByNameAndHidesPending(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(
            CompanySearchController::URL.'?q='.urlencode(mb_strtolower($ctx['marker'])),
            $ctx['token'],
        );
        self::assertResponseStatusCodeSame(200);
        $items = self::itemsOf($client);

        $ids = array_column($items, 'id');
        self::assertContains($ctx['activeId'], $ids);
        self::assertNotContains($ctx['pendingId'], $ids);
    }

    public function testFindsCompanyByInn(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(CompanySearchController::URL.'?q='.$ctx['inn'], $ctx['token']);
        self::assertResponseStatusCodeSame(200);
        $ids = array_column(self::itemsOf($client), 'id');
        self::assertContains($ctx['activeId'], $ids);
    }

    public function testResultIsBriefAndCarriesNoCompanyDetails(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(
            CompanySearchController::URL.'?q='.urlencode($ctx['marker']),
            $ctx['token'],
        );
        self::assertResponseStatusCodeSame(200);
        $items = self::itemsOf($client);
        self::assertNotEmpty($items);

        $row = $items[0];
        self::assertSame(['id', 'legal_name', 'inn', 'type'], array_keys($row));
        // Реквизиты компании в подсказке контрагента не нужны и не отдаются.
        self::assertArrayNotHasKey('address', $row);
        self::assertArrayNotHasKey('contacts', $row);
    }

    public function testShortQueryIsRejected(): void
    {
        self::client();
        $ctx = self::context();

        self::request(CompanySearchController::URL, $ctx['token']);
        self::assertResponseStatusCodeSame(422);

        self::request(CompanySearchController::URL.'?q=%D0%9E', $ctx['token']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthorized(): void
    {
        self::client();
        self::request(CompanySearchController::URL.'?q=test', null);
        self::assertResponseStatusCodeSame(401);
    }
}
