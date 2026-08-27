<?php

declare(strict_types=1);

namespace App\Tests\Functional\Contract;

use App\Contract\Controller\ContractCreateController;
use App\Contract\Controller\ContractGetController;
use App\Contract\Controller\ContractListController;
use App\Contract\Controller\ContractSendForSignatureController;
use App\Contract\Controller\ContractSignController;
use App\Contract\Controller\ContractTypeCreateController;
use App\Contract\Controller\ContractTypeListController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ContractTypeFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * договоры (FR-1.4.3/1.4.8, AM-9).
 *
 * - Рамочный договор вне тендера (source=external): создание заказчиком (draft),
 *   multi_use по умолчанию; source=tender → 422;
 * - Жизненный цикл через API: draft → send-for-signature → pending_signature →
 *   подпись заказчика (остаётся pending) → подпись исполнителя → signed;
 * - Список/карточка: видят обе стороны; чужая компания → 404;
 * - Права: создание — contracts.create (заказчик admin/manager; agent/поставщик → 403);
 *   подписание — обе стороны (ContractVoter::SIGN); отправка на подписание — заказчик;
 * - Справочник contract-types: list для всех, create — platform_admin;
 * - Outbox-события contract.created/pending_signature/signed.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: findings порождает прод-код внутри HTTP-запросов — AuthMiddleware:84
 * (SELECT пользователя на каждый запрос), AuditService:75 (append-only аудит с
 * батчингом flush), visibility-подзапросы ContractRepository:188/BidRepository:152
 * (дубликат на каждый запрос). Прод-код не меняем — правила отключены атрибутами
 * класса, см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
#[IgnoreRule('query-in-loop')]
#[IgnoreRule('duplicate-query')]
final class ContractFlowTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private Company $customer;
    private Company $supplier;
    private string $customerToken;
    private string $supplierToken;
    private int $contractTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $this->customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $this->customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cust-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $this->supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->customerToken = $this->loginAs((string) $customerUser->getEmail());
        $this->supplierToken = $this->loginAs((string) $supplierUser->getEmail());
        $this->contractTypeId = (int) ContractTypeFactory::createOne()->getId();
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
        return '22.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, mixed> $data
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

    /**
     * Создание договора заказчиком от лица своей компании.
     *
     * @return KernelBrowser клиент с ответом создания (201)
     */
    private function createContractByCustomer(): KernelBrowser
    {
        return self::request('POST', ContractCreateController::URL, $this->customerToken, self::contractPayload(
            $this->contractTypeId,
            (string) $this->customer->getId(),
            (string) $this->supplier->getId(),
        ));
    }

    /**
     * id договора из JSON-ответа.
     */
    private static function contractId(KernelBrowser $client): string
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function contractPayload(int $contractTypeId, string $customerId, string $supplierId): array
    {
        return [
            'contract_type_id' => (string) $contractTypeId,
            'customer_id' => $customerId,
            'supplier_id' => $supplierId,
            'scope' => 'multi_use',
            'price_net_minor' => 1000000,
            'vat_rate' => 20,
            'price_basis' => 'net',
            'valid_from' => '2026-01-01',
            'valid_to' => '2027-01-01',
        ];
    }

    public function testCreateExternalContractAndSignBothParties(): void
    {
        $client = $this->createContractByCustomer();
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['id']);
        $contractId = $body['id'];
        self::assertSame('draft', $body['status']);
        self::assertSame('external', $body['source']);
        self::assertSame('multi_use', $body['scope']);
        self::assertIsString($body['number']);
        self::assertSame('C-', substr($body['number'], 0, 2));
        self::assertSame(1000000, $body['price_net_minor']);

        // C1: отправка на подписание (заказчик) → pending_signature.
        $client = self::request(
            'POST',
            str_replace('{contractId}', $contractId, ContractSendForSignatureController::URL),
            $this->customerToken,
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('pending_signature', $body['status']);

        // C2: подпись заказчика — договор остаётся pending (нужны обе подписи).
        $client = self::request(
            'POST',
            str_replace('{contractId}', $contractId, ContractSignController::URL),
            $this->customerToken,
            ['party' => 'customer', 'signature' => 'sign-cust'],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('pending_signature', $body['status']);

        // Подпись исполнителя → signed.
        $client = self::request(
            'POST',
            str_replace('{contractId}', $contractId, ContractSignController::URL),
            $this->supplierToken,
            ['party' => 'supplier', 'signature' => 'sign-supp'],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('signed', $body['status']);
        self::assertNotNull($body['signed_at']);

        // Outbox-события договора.
        $rows = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeQuery(
                'SELECT event_type FROM outbox_events WHERE aggregate_type = :t AND aggregate_id = :id',
                ['t' => 'contract', 'id' => $contractId],
            )
            ->fetchFirstColumn();
        self::assertContains('contract.created', $rows);
        self::assertContains('contract.pending_signature', $rows);
        self::assertContains('contract.signed', $rows);
    }

    public function testListAndGetVisibleToBothParties(): void
    {
        $contractId = self::contractId($this->createContractByCustomer());

        // Список для заказчика и для исполнителя.
        $client = self::request('GET', ContractListController::URL, $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        $ids = array_column($list['items'], 'id');
        self::assertContains($contractId, $ids);

        $client = self::request('GET', ContractListController::URL, $this->supplierToken);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        $ids = array_column($list['items'], 'id');
        self::assertContains($contractId, $ids);

        // Карточка доступна обеим сторонам.
        $client = self::request('GET', str_replace('{contractId}', $contractId, ContractGetController::URL), $this->supplierToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($contractId, $body['id']);
    }

    public function testThirdPartyCannotViewContractReturns404(): void
    {
        $contractId = self::contractId($this->createContractByCustomer());

        $outsider = CompanyFactory::new()->approved()->create();
        $outsiderUser = UserFactory::createOne([
            'companyId' => $outsider->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'outsider-'.random_int(1000, 999999).'@test.ru',
        ]);
        $outsiderToken = $this->loginAs((string) $outsiderUser->getEmail());

        $client = self::request('GET', str_replace('{contractId}', $contractId, ContractGetController::URL), $outsiderToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCustomerIdMismatchReturns422(): void
    {
        // Суперадмин-набор у admin не различает тип компании (IAM: admin = полный
        // набор прав), поэтому создание договора от чужого заказчика блокирует
        // сервис: customer_id обязан совпадать с компанией актора → 422.
        $client = self::request('POST', ContractCreateController::URL, $this->supplierToken, self::contractPayload(
            $this->contractTypeId,
            (string) $this->customer->getId(),
            (string) $this->supplier->getId(),
        ));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAgentCannotCreateContractReturns403(): void
    {
        $agent = UserFactory::createOne([
            'companyId' => $this->customer->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'cust-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agentToken = $this->loginAs((string) $agent->getEmail());

        $client = self::request('POST', ContractCreateController::URL, $agentToken, self::contractPayload(
            $this->contractTypeId,
            (string) $this->customer->getId(),
            (string) $this->supplier->getId(),
        ));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSourceTenderReturns422(): void
    {
        $payload = self::contractPayload(
            $this->contractTypeId,
            (string) $this->customer->getId(),
            (string) $this->supplier->getId(),
        );
        $payload['source'] = 'tender';

        $client = self::request('POST', ContractCreateController::URL, $this->customerToken, $payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSupplierCannotSendForSignatureReturns409(): void
    {
        $contractId = self::contractId($this->createContractByCustomer());

        // Исполнитель — сторона (voter пропускает), но отправить может только заказчик.
        $client = self::request(
            'POST',
            str_replace('{contractId}', $contractId, ContractSendForSignatureController::URL),
            $this->supplierToken,
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testSignBeforeSendReturns409(): void
    {
        $contractId = self::contractId($this->createContractByCustomer());

        $client = self::request(
            'POST',
            str_replace('{contractId}', $contractId, ContractSignController::URL),
            $this->customerToken,
            ['party' => 'customer'],
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testDoubleSignReturns409(): void
    {
        $contractId = self::contractId($this->createContractByCustomer());
        self::request('POST', str_replace('{contractId}', $contractId, ContractSendForSignatureController::URL), $this->customerToken);

        $client = self::request(
            'POST',
            str_replace('{contractId}', $contractId, ContractSignController::URL),
            $this->customerToken,
            ['party' => 'customer'],
        );
        self::assertResponseStatusCodeSame(200);

        $client = self::request(
            'POST',
            str_replace('{contractId}', $contractId, ContractSignController::URL),
            $this->customerToken,
            ['party' => 'customer'],
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = self::request('POST', ContractCreateController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testContractTypeListAndCreateByPlatformAdmin(): void
    {
        $sa = UserFactory::createOne([
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'email' => 'sa-contract-'.random_int(1000, 999999).'@test.ru',
        ]);
        $saToken = $this->loginAs((string) $sa->getEmail());

        // Список типов (справочник для всех).
        $client = self::request('GET', ContractTypeListController::URL, $saToken);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        $codes = array_column($list['items'], 'code');
        self::assertContains('base', $codes);

        // Создание типа суперадмином (multi_use по умолчанию — флаг не передан).
        $code = 'type-'.random_int(1000, 999999);
        $client = self::request('POST', ContractTypeCreateController::URL, $saToken, [
            'code' => $code,
            'name' => 'Рамочный тип',
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($code, $body['code']);
        self::assertFalse($body['is_single_use']);
        // id — bigint (справочник contract_types использует целочисленные id)
        self::assertIsNumeric($body['id']);
    }
}
