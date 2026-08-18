<?php

declare(strict_types=1);

namespace App\Tests\Functional\Contract;

use App\Contract\Controller\ContractScanController;
use App\Contract\Entity\Contract;
use App\Contract\Entity\ContractDocument;
use App\Iam\Controller\Auth\TokenController;
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
     * @return array{customer: \App\Iam\Entity\Company, supplier: \App\Iam\Entity\Company,
     *               customerToken: string, supplierToken: string, contract: Contract}
     */
    private static function contractContext(): array
    {
        self::client();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'scan-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'scan-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $type = ContractTypeFactory::createOne();
        $contract = new Contract(
            number: 'C-SCAN-'.random_int(100000, 999999),
            contractTypeId: (int) $type->getId(),
            customerId: $customer->getId(),
            supplierId: $supplier->getId(),
            priceNetMinor: 1_000_000,
        );
        $em->persist($contract);
        $em->flush();

        return [
            'customer' => $customer,
            'supplier' => $supplier,
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'supplierToken' => self::loginAs((string) $supplierUser->getEmail()),
            'contract' => $contract,
        ];
    }

    private static function scanUrl(string $contractId): string
    {
        return str_replace('{contractId}', $contractId, ContractScanController::URL);
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
        $ctx = self::contractContext();

        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            self::scanUrl((string) $ctx['contract']->getId()),
            [],
            ['file' => self::pdf()],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$ctx['supplierToken']],
        );
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        // Контракт эндпоинта — форма openapi Document (entity_type/scope).
        self::assertSame('contract', $body['entity_type']);
        self::assertSame('contract', $body['scope']);
        self::assertSame((string) $ctx['contract']->getId(), $body['entity_id']);
        self::assertIsString($body['id']);
        self::assertIsArray($body['versions']);
        self::assertNotEmpty($body['versions']);

        // contract_documents связь в БД.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $scan = $em->getRepository(ContractDocument::class)->findOneBy(['contractId' => $ctx['contract']->getId()]);
        self::assertInstanceOf(ContractDocument::class, $scan);
        self::assertSame((string) $scan->getDocumentId(), $body['id']);
    }

    public function testCustomerUploadsContractScan(): void
    {
        $ctx = self::contractContext();

        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            self::scanUrl((string) $ctx['contract']->getId()),
            [],
            ['file' => self::pdf()],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$ctx['customerToken']],
        );
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('contract', $body['entity_type']);
        self::assertSame('contract', $body['scope']);
        self::assertSame('executor', $body['owner_role']);
    }

    public function testThirdPartyCannotUploadScan(): void
    {
        $ctx = self::contractContext();
        $outsider = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $outsiderUser = UserFactory::createOne([
            'companyId' => $outsider->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'scan-out-'.random_int(1000, 999999).'@test.ru',
        ]);
        $outsiderToken = self::loginAs((string) $outsiderUser->getEmail());

        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            self::scanUrl((string) $ctx['contract']->getId()),
            [],
            ['file' => self::pdf()],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$outsiderToken],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testAgentCannotUploadScanEvenAsParty(): void
    {
        // contracts.scan_upload (group supplier): admin/manager — да, agent — 403
        // (ContractVoter::SCAN), даже если agent — сторона договора.
        $ctx = self::contractContext();
        $agentUser = UserFactory::createOne([
            'companyId' => $ctx['supplier']->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'scan-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agentToken = self::loginAs((string) $agentUser->getEmail());

        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            self::scanUrl((string) $ctx['contract']->getId()),
            [],
            ['file' => self::pdf()],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$agentToken],
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $ctx = self::contractContext();

        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            self::scanUrl((string) $ctx['contract']->getId()),
            [],
            ['file' => self::pdf()],
        );
        self::assertResponseStatusCodeSame(401);
    }
}
