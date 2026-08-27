<?php

declare(strict_types=1);

namespace App\Tests\Functional\Contract;

use App\Contract\Controller\ContractScanController;
use App\Contract\Entity\Contract;
use App\Contract\Entity\ContractDocument;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ContractTypeFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Задача 5.7: скан договора (FR-1.4.7, UC-08a, POST /contracts/{id}/scan).
 *
 * - исполнитель прикладывает скан → 201, contract_documents связь;
 * - заказчик (сторона) тоже может приложить;
 * - чужой актор → 404 (party-изоляция);
 * - не аутентифицированный → 401.
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ContractScanTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private Company $customer;
    private Company $supplier;
    private string $customerToken;
    private string $supplierToken;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $this->customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'scan-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $this->supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $this->supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'scan-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $type = ContractTypeFactory::createOne();
        $this->contract = new Contract(
            number: 'C-SCAN-'.random_int(100000, 999999),
            contractTypeId: (int) $type->getId(),
            customerId: $this->customer->getId(),
            supplierId: $this->supplier->getId(),
            priceNetMinor: 1_000_000,
        );
        $em->persist($this->contract);
        $em->flush();

        $this->customerToken = $this->loginAs((string) $customerUser->getEmail());
        $this->supplierToken = $this->loginAs((string) $supplierUser->getEmail());
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
        return '34.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private static function scanUrl(string $contractId): string
    {
        return str_replace('{contractId}', $contractId, ContractScanController::URL);
    }

    /**
     * Загрузка скана от имени токена.
     */
    private function uploadScan(string $token): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            self::scanUrl((string) $this->contract->getId()),
            [],
            ['file' => self::pdf()],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        return $client;
    }

    private static function pdf(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'scan');
        if (false === $tmp) {
            self::fail('Unable to create temp file');
        }
        file_put_contents($tmp, 'contract-scan-content-'.random_int(1000, 999999));

        return new UploadedFile($tmp, 'scan.pdf', 'application/pdf', \UPLOAD_ERR_OK, true);
    }

    public function testSupplierUploadsContractScan(): void
    {
        $client = $this->uploadScan($this->supplierToken);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        // Контракт эндпоинта — форма openapi Document (entity_type/scope).
        self::assertSame('contract', $body['entity_type']);
        self::assertSame('contract', $body['scope']);
        self::assertSame((string) $this->contract->getId(), $body['entity_id']);
        self::assertIsString($body['id']);
        self::assertIsArray($body['versions']);
        self::assertNotEmpty($body['versions']);

        // contract_documents связь в БД.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $scan = $em->getRepository(ContractDocument::class)->findOneBy(['contractId' => $this->contract->getId()]);
        self::assertInstanceOf(ContractDocument::class, $scan);
        self::assertSame((string) $scan->getDocumentId(), $body['id']);
    }

    public function testCustomerUploadsContractScan(): void
    {
        $client = $this->uploadScan($this->customerToken);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('contract', $body['entity_type']);
        self::assertSame('contract', $body['scope']);
        self::assertSame('executor', $body['owner_role']);
    }

    public function testThirdPartyCannotUploadScan(): void
    {
        $outsider = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $outsiderUser = UserFactory::createOne([
            'companyId' => $outsider->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'scan-out-'.random_int(1000, 999999).'@test.ru',
        ]);
        $outsiderToken = $this->loginAs((string) $outsiderUser->getEmail());

        $this->uploadScan($outsiderToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAgentCannotUploadScanEvenAsParty(): void
    {
        // contracts.scan_upload (group supplier): admin/manager — да, agent — 403
        // (ContractVoter::SCAN), даже если agent — сторона договора.
        $agentUser = UserFactory::createOne([
            'companyId' => $this->supplier->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'scan-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agentToken = $this->loginAs((string) $agentUser->getEmail());

        $this->uploadScan($agentToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            self::scanUrl((string) $this->contract->getId()),
            [],
            ['file' => self::pdf()],
        );
        self::assertResponseStatusCodeSame(401);
    }
}
