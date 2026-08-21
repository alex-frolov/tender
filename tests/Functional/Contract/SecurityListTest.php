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

    /**
     * @return array{customerToken: string, supplierToken: string, bid: Security, contract: Security, foreign: Security}
     */
    private static function context(): array
    {
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

        return [
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'supplierToken' => self::loginAs((string) $supplierUser->getEmail()),
            'bid' => self::security($customer->getId(), $supplier->getId(), SecurityKindEnum::BID),
            'contract' => self::security($customer->getId(), $supplier->getId(), SecurityKindEnum::CONTRACT),
            'foreign' => self::security(Uuid::v4(), Uuid::v4(), SecurityKindEnum::BID),
        ];
    }

    public function testCustomerSeesSecuritiesOfOwnProcedures(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(SecurityListController::URL, $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertArrayHasKey('next_cursor', $body);

        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $ctx['bid']->getId(), $ids);
        self::assertContains((string) $ctx['contract']->getId(), $ids);
        self::assertNotContains((string) $ctx['foreign']->getId(), $ids);

        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertArrayHasKey('kind', $item);
        self::assertArrayHasKey('amount_minor', $item);
        self::assertArrayHasKey('status', $item);
        self::assertArrayHasKey('currency', $item);
    }

    public function testPerformerSeesOwnDeposits(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(SecurityListController::URL, $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $ctx['bid']->getId(), $ids);
        self::assertNotContains((string) $ctx['foreign']->getId(), $ids);
    }

    public function testFilterByKindNarrowsList(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(SecurityListController::URL.'?kind=contract', $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $ctx['contract']->getId(), $ids);
        self::assertNotContains((string) $ctx['bid']->getId(), $ids);
    }

    public function testUnknownKindIsRejected(): void
    {
        self::client();
        $ctx = self::context();

        self::request(SecurityListController::URL.'?kind=deposit', $ctx['customerToken']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthorized(): void
    {
        self::client();
        self::request(SecurityListController::URL, null);
        self::assertResponseStatusCodeSame(401);
    }
}
