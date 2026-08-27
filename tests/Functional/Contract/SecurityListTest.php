<?php

declare(strict_types=1);

namespace App\Tests\Functional\Contract;

use App\Contract\Controller\SecurityListController;
use App\Contract\Entity\Enum\SecurityBasisEnum;
use App\Contract\Entity\Enum\SecurityKindEnum;
use App\Contract\Entity\Enum\SecurityTypeEnum;
use App\Contract\Entity\Security;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GET /securities (FR-1.4.1/1.4.2): обеспечение компании актора.
 *
 * - заказчик видит обеспечение по своим процедурам (tenant), исполнитель —
 *   внесённое им (supplier);
 * - чужое обеспечение не отдаётся;
 * - фильтр ?kind= сужает выборку, неизвестное значение → 422;
 * - 401 без токена.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class SecurityListTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private string $customerToken;
    private string $supplierToken;
    private Security $bid;
    private Security $contract;
    private Security $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'sec-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'sec-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->customerToken = $this->loginAs((string) $customerUser->getEmail());
        $this->supplierToken = $this->loginAs((string) $supplierUser->getEmail());
        $this->bid = self::security($customer->getId(), $supplier->getId(), SecurityKindEnum::BID);
        $this->contract = self::security($customer->getId(), $supplier->getId(), SecurityKindEnum::CONTRACT);
        $this->foreign = self::security(Uuid::v4(), Uuid::v4(), SecurityKindEnum::BID);
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
        return '78.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function request(string $url, ?string $token): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'GET',
            $url,
            [],
            [],
            null === $token
                ? ['CONTENT_TYPE' => 'application/json']
                : ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        return $client;
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
            json_encode(['email' => $email, 'password' => UserFactory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    private static function security(Uuid $tenantId, Uuid $supplierId, SecurityKindEnum $kind): Security
    {
        $security = new Security(
            tenantId: $tenantId,
            kind: $kind,
            entityType: SecurityKindEnum::BID === $kind ? 'bid' : 'contract',
            entityId: Uuid::v4(),
            supplierId: $supplierId,
            type: SecurityTypeEnum::BLOCKED_FUNDS,
            amountMinor: 250_000,
            calculationBasis: SecurityBasisEnum::NMCK,
            basisAmountMinor: 5_000_000,
        );
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($security);
        $em->flush();

        return $security;
    }

    public function testCustomerSeesSecuritiesOfOwnProcedures(): void
    {
        $client = self::request(SecurityListController::URL, $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertArrayHasKey('next_cursor', $body);

        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $this->bid->getId(), $ids);
        self::assertContains((string) $this->contract->getId(), $ids);
        self::assertNotContains((string) $this->foreign->getId(), $ids);

        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertArrayHasKey('kind', $item);
        self::assertArrayHasKey('amount_minor', $item);
        self::assertArrayHasKey('status', $item);
        self::assertArrayHasKey('currency', $item);
    }

    public function testPerformerSeesOwnDeposits(): void
    {
        $client = self::request(SecurityListController::URL, $this->supplierToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $this->bid->getId(), $ids);
        self::assertNotContains((string) $this->foreign->getId(), $ids);
    }

    public function testFilterByKindNarrowsList(): void
    {
        $client = self::request(SecurityListController::URL.'?kind=contract', $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $this->contract->getId(), $ids);
        self::assertNotContains((string) $this->bid->getId(), $ids);
    }

    public function testUnknownKindIsRejected(): void
    {
        self::request(SecurityListController::URL.'?kind=deposit', $this->customerToken);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthorized(): void
    {
        self::request(SecurityListController::URL, null);
        self::assertResponseStatusCodeSame(401);
    }
}
