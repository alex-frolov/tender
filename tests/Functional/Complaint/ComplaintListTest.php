<?php

declare(strict_types=1);

namespace App\Tests\Functional\Complaint;

use App\Complaint\Controller\ComplaintCreateController;
use App\Complaint\Controller\ComplaintListController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Список жалоб (FR-1.2.10, GET /complaints).
 *
 * - подавший видит свою жалобу;
 * - заказчик видит жалобу на свою процедуру (разбирательство двустороннее);
 * - посторонняя компания не видит ни ту, ни другую;
 * - фильтр ?tender_id= сужает выборку, невалидный uuid → 422;
 * - 401 без токена.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ComplaintListTest extends WebTestCase
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
        return '75.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private static function request(string $method, string $url, ?string $token, ?array $data = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
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
     * @return list<string>
     */
    private static function idsOf(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertArrayHasKey('next_cursor', $body);

        /** @var list<string> $ids */
        $ids = array_column($body['items'], 'id');

        return $ids;
    }

    /**
     * Контекст: жалоба поставщика на тендер заказчика плюс посторонняя компания.
     *
     * @return array{customerToken: string, supplierToken: string, outsiderToken: string, complaintId: string, tenderId: string}
     */
    private static function context(): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $outsider = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();

        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cmp-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cmp-supp-'.random_int(1000, 999999).'@test.ru',
        ]);
        $outsiderUser = UserFactory::createOne([
            'companyId' => $outsider->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cmp-out-'.random_int(1000, 999999).'@test.ru',
        ]);

        $tender = TenderFactory::createOne([
            'customerId' => $customer->getId(),
            'createdBy' => $customer->getId(),
            'status' => TenderStatusEnum::ACCEPTING_BIDS,
        ]);

        $supplierToken = self::loginAs((string) $supplierUser->getEmail());
        $client = self::request(
            'POST',
            str_replace('{tenderId}', (string) $tender->getId(), ComplaintCreateController::URL),
            $supplierToken,
            ['text' => 'Документация ограничивает конкуренцию', 'ground' => 'Нарушение п. 1 ст. 33'],
        );
        self::assertResponseStatusCodeSame(201);
        $complaint = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($complaint);
        self::assertIsString($complaint['id']);

        return [
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'supplierToken' => $supplierToken,
            'outsiderToken' => self::loginAs((string) $outsiderUser->getEmail()),
            'complaintId' => $complaint['id'],
            'tenderId' => (string) $tender->getId(),
        ];
    }

    public function testFilerSeesOwnComplaint(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request('GET', ComplaintListController::URL, $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(200);
        self::assertContains($ctx['complaintId'], self::idsOf($client));
    }

    public function testCustomerSeesComplaintOnOwnTender(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request('GET', ComplaintListController::URL, $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        self::assertContains($ctx['complaintId'], self::idsOf($client));
    }

    public function testOutsiderSeesNothing(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request('GET', ComplaintListController::URL, $ctx['outsiderToken']);
        self::assertResponseStatusCodeSame(200);
        self::assertNotContains($ctx['complaintId'], self::idsOf($client));
    }

    public function testFilterByTender(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(
            'GET',
            ComplaintListController::URL.'?tender_id='.$ctx['tenderId'],
            $ctx['customerToken'],
        );
        self::assertResponseStatusCodeSame(200);
        self::assertContains($ctx['complaintId'], self::idsOf($client));
    }

    public function testInvalidTenderIdIsRejected(): void
    {
        self::client();
        $ctx = self::context();

        self::request('GET', ComplaintListController::URL.'?tender_id=not-a-uuid', $ctx['customerToken']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthorized(): void
    {
        self::client();
        self::request('GET', ComplaintListController::URL, null);
        self::assertResponseStatusCodeSame(401);
    }
}
