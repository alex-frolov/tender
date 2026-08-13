<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Controller\TenderCreateController;
use App\Tender\Controller\TenderGetController;
use App\Tender\Controller\TenderListController;
use App\Tender\Controller\TenderUpdateController;
use App\Tender\Entity\Tender;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.1.1: CRUD тендера + черновик.
 * - создание черновика (status=draft) с лотами и инвариантом суммы (FR-1.1.7);
 * - список и карточка — только в рамках компании актора (tenant-изоляция);
 * - правка допустимых полей до окончания приёма заявок;
 * - валидация (422), 404 для чужого/несуществующего, 401 без токена.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class TenderCrudTest extends WebTestCase
{
    private const EMAIL = VerifiedUserStory::EMAIL;
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

    private static function login(): string
    {
        return self::loginAs(self::EMAIL);
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

    /**
     * @return array<string, mixed>
     */
    private static function createPayload(string $companyId): array
    {
        return [
            'title' => 'Закупка ИТ-оборудования',
            'description' => 'Описание закупки',
            'procedure_type' => 'auction',
            'law_type' => 'commercial',
            'nmck_minor' => 100000,
            'no_start_price' => false,
            'currency' => 'RUB',
            'vat_rate' => 20,
            'price_basis' => 'net',
            'customer_id' => $companyId,
            'region' => 'Москва',
            'access_type' => 'open',
            'lots' => [
                ['title' => 'Серверы', 'price_net_minor' => 60000],
                ['title' => 'СХД', 'price_net_minor' => 40000],
            ],
        ];
    }

    public function testCreateTenderDraftWithLots(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $token = self::login();

        $client = self::request('POST', TenderCreateController::URL, $token, self::createPayload((string) $company->getId()));
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('draft', $body['status']);
        self::assertSame('Закупка ИТ-оборудования', $body['title']);
        self::assertSame('auction', $body['procedure_type']);
        self::assertEquals(100000, $body['nmck_minor']);
        self::assertIsArray($body['lots']);
        self::assertCount(2, $body['lots']);
        $firstLot = $body['lots'][0];
        self::assertIsArray($firstLot);
        self::assertEquals(60000, $firstLot['price_net_minor']);
        self::assertEquals(72000, $firstLot['price_gross_minor']);
        self::assertEquals(20, $body['vat_rate']);
        self::assertIsString($body['number']);
        self::assertStringStartsWith('T-', $body['number']);
    }

    public function testCreateValidatesMissingRequiredFields(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $token = self::login();

        $client = self::request('POST', TenderCreateController::URL, $token, [
            'title' => '',
            'procedure_type' => 'auction',
            'currency' => 'RUB',
            'price_basis' => 'net',
            'customer_id' => (string) $company->getId(),
            'lots' => [['title' => 'Лот', 'price_net_minor' => 1]],
        ]);
        self::assertResponseStatusCodeSame(422);

        $client = self::request('POST', TenderCreateController::URL, $token, [
            'title' => 'Без лотов',
            'procedure_type' => 'auction',
            'currency' => 'RUB',
            'price_basis' => 'net',
            'customer_id' => (string) $company->getId(),
            'lots' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRejectsLotsSumMismatch(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $token = self::login();

        $payload = self::createPayload((string) $company->getId());
        // сумма лотов 90000 != nmck 100000 → инвариант (FR-1.1.7)
        $payload['lots'] = [
            ['title' => 'Серверы', 'price_net_minor' => 50000],
            ['title' => 'СХД', 'price_net_minor' => 40000],
        ];
        $client = self::request('POST', TenderCreateController::URL, $token, $payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testListAndGetTender(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId()]);
        $token = self::login();

        $client = self::request('GET', TenderListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        self::assertGreaterThanOrEqual(1, \count($list['items']));
        $item = $list['items'][0];
        self::assertIsArray($item);
        self::assertIsString($item['id']);
        $id = $item['id'];

        $url = str_replace('{tenderId}', $id, TenderGetController::URL);
        $client = self::request('GET', $url, $token);
        self::assertResponseStatusCodeSame(200);
        $single = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($single);
        self::assertSame($id, $single['id']);
    }

    public function testGetTenderFromAnotherTenantReturns404(): void
    {
        self::client();
        VerifiedUserStory::company();
        // тендер другого tenant (customer != company актора)
        TenderFactory::createOne(['customerId' => \Symfony\Component\Uid\Uuid::v4()]);
        $token = self::login();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $others = $em->getRepository(Tender::class)->findAll();
        self::assertNotEmpty($others);
        $other = end($others);
        self::assertInstanceOf(Tender::class, $other);

        $url = str_replace('{tenderId}', (string) $other->getId(), TenderGetController::URL);
        $client = self::request('GET', $url, $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateTenderFields(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $tender = TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId()]);
        $token = self::login();

        $url = str_replace('{tenderId}', (string) $tender->getId(), TenderUpdateController::URL);
        $client = self::request('PATCH', $url, $token, [
            'title' => 'Новое наименование',
            'region' => 'Санкт-Петербург',
            'change_reason' => 'Уточнение требований',
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Новое наименование', $body['title']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(Tender::class)->find((string) $tender->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Новое наименование', $reloaded->getTitle());
        self::assertSame('Санкт-Петербург', $reloaded->getRegion());
    }

    public function testPatchUnknownTenderReturns404(): void
    {
        self::client();
        VerifiedUserStory::company();
        $token = self::login();

        $url = str_replace('{tenderId}', '00000000-0000-0000-0000-000000000000', TenderUpdateController::URL);
        $client = self::request('PATCH', $url, $token, ['title' => 'X']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::client();
        $client = self::request('GET', TenderListController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAgentCannotCreateTenderReturns403(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $agent = UserFactory::createOne([
            'role' => UserRoleEnum::AGENT,
            'companyId' => $company->getId(),
        ]);
        $token = self::loginAs((string) $agent->getEmail());

        $client = self::request('POST', TenderCreateController::URL, $token, self::createPayload((string) $company->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAgentCannotUpdateTenderReturns403(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $agent = UserFactory::createOne([
            'role' => UserRoleEnum::AGENT,
            'companyId' => $company->getId(),
        ]);
        TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId()]);
        $token = self::loginAs((string) $agent->getEmail());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tender = $em->getRepository(Tender::class)->findOneBy(['tenantId' => $company->getId()]);
        self::assertNotNull($tender);

        $url = str_replace('{tenderId}', (string) $tender->getId(), TenderUpdateController::URL);
        $client = self::request('PATCH', $url, $token, ['title' => 'X']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAgentCanViewTenders(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $agent = UserFactory::createOne([
            'role' => UserRoleEnum::AGENT,
            'companyId' => $company->getId(),
        ]);
        TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId()]);
        $token = self::loginAs((string) $agent->getEmail());

        $client = self::request('GET', TenderListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
    }

    public function testManagerCanCreateAndUpdateTender(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $manager = UserFactory::createOne([
            'role' => UserRoleEnum::MANAGER,
            'companyId' => $company->getId(),
        ]);
        $token = self::loginAs((string) $manager->getEmail());

        $client = self::request('POST', TenderCreateController::URL, $token, self::createPayload((string) $company->getId()));
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        $url = str_replace('{tenderId}', $body['id'], TenderUpdateController::URL);
        $client = self::request('PATCH', $url, $token, ['title' => 'Управлено менеджером']);
        self::assertResponseStatusCodeSame(200);
    }
}
