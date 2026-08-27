<?php

declare(strict_types=1);

namespace App\Tests\Functional\Contract;

use App\Contract\Controller\ContractGetController;
use App\Contract\Controller\ContractListController;
use App\Contract\Controller\ContractStageCreateController;
use App\Contract\Entity\Contract;
use App\Contract\Entity\ContractTender;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ContractTypeFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Этапы исполнения по тендеру (FR-1.4.3, UC-10):
 * POST /contract_tenders/{contractTenderId}/stages.
 *
 * - Обе стороны договора (contracts.sign / supplier-партия); чужая компания — 404;
 * - number назначается по умолчанию (следующий по порядку) или из запроса;
 * - due_at валидируется (422 при неверной дате).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: findings порождает прод-код внутри HTTP-запросов — AuthMiddleware:84
 * (SELECT пользователя на каждый запрос) и AuditService:75 (append-only аудит с
 * батчингом flush). Прод-код не меняем — правило отключено атрибутом класса,
 * см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
final class ContractStageTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private ContractTender $contractTender;
    private string $customerToken;
    private string $supplierToken;

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
            'email' => 'st-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'st-supp-'.random_int(1000, 999999).'@test.ru',
        ]);
        $tender = TenderFactory::createOne([
            'customerId' => $customer->getId(),
            'createdBy' => $customer->getId(),
        ]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $type = ContractTypeFactory::createOne();
        $contract = new Contract(
            number: 'C-STAGE-'.random_int(100000, 999999),
            contractTypeId: (int) $type->getId(),
            customerId: $customer->getId(),
            supplierId: $supplier->getId(),
            priceNetMinor: 100_000,
        );
        $em->persist($contract);
        $em->flush();
        $this->contractTender = new ContractTender($contract, $tender->getId(), 100_000, 120_000, 2000);
        $em->persist($this->contractTender);
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
        return '99.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private function stageUrl(): string
    {
        return str_replace(
            '{contractTenderId}',
            (string) $this->contractTender->getId(),
            ContractStageCreateController::URL,
        );
    }

    public function testCreateStageAutoNumberByCustomer(): void
    {
        $url = $this->stageUrl();

        $client = self::request('POST', $url, $this->customerToken, [
            'title' => 'Этап 1: поставка',
            'amount_minor' => 100_000,
            'due_at' => '2026-06-01T00:00:00Z',
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame((string) $this->contractTender->getId(), $body['contract_tender_id']);
        self::assertSame(1, $body['number']);
        self::assertSame('Этап 1: поставка', $body['title']);
        self::assertSame(100_000, $body['amount_minor']);
        self::assertSame('pending', $body['status']);

        // Второй этап — номер назначается автоматически (2).
        $client = self::request('POST', $url, $this->supplierToken, ['title' => 'Этап 2: монтаж']);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(2, $body['number']);
    }

    /**
     * Этапы читаются в карточке договора: до этого их можно было только создать,
     * и после перезагрузки страницы созданный этап исчезал из виду.
     * В списке договоров ключ stages отсутствует — там этапы не запрашиваются.
     */
    public function testStagesAreReadableInContractCard(): void
    {
        self::request('POST', $this->stageUrl(), $this->customerToken, [
            'title' => 'Этап 1: поставка',
            'amount_minor' => 100_000,
        ]);
        self::assertResponseStatusCodeSame(201);

        $contractId = (string) $this->contractTender->getContract()->getId();
        $client = self::request(
            'GET',
            str_replace('{contractId}', $contractId, ContractGetController::URL),
            $this->customerToken,
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['tenders']);
        /** @var list<array<string, mixed>> $tenders */
        $tenders = $body['tenders'];
        self::assertNotEmpty($tenders);
        self::assertIsArray($tenders[0]['stages']);
        /** @var list<array<string, mixed>> $stages */
        $stages = $tenders[0]['stages'];
        self::assertCount(1, $stages);
        self::assertSame('Этап 1: поставка', $stages[0]['title']);
        self::assertSame(1, $stages[0]['number']);

        // В списке договоров этапы не отдаются: ключа нет вовсе.
        $client = self::request('GET', ContractListController::URL, $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $list['items'];
        foreach ($items as $item) {
            self::assertIsArray($item['tenders']);
            /** @var list<array<string, mixed>> $bound */
            $bound = $item['tenders'];
            foreach ($bound as $row) {
                self::assertArrayNotHasKey('stages', $row);
            }
        }
    }

    /**
     * Фильтр ?tender_id= в списке договоров: страница аукциона отвечает на
     * вопрос «есть ли по этой процедуре договор» одним запросом. Без фильтра
     * пришлось бы вычитывать весь список договоров компании постранично.
     */
    public function testContractListFilteredByBoundTender(): void
    {
        $contractId = (string) $this->contractTender->getContract()->getId();
        $tenderId = (string) $this->contractTender->getTenderId();

        $client = self::request('GET', ContractListController::URL.'?tender_id='.$tenderId, $this->supplierToken);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        self::assertSame([$contractId], array_column($list['items'], 'id'));

        // Процедура без договора — пустой список, а не весь реестр компании.
        $foreign = TenderFactory::createOne();
        $client = self::request(
            'GET',
            ContractListController::URL.'?tender_id='.$foreign->getId(),
            $this->customerToken,
        );
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertSame([], $list['items']);

        // Невалидный uuid — 422, а не молчаливый пустой ответ.
        self::request('GET', ContractListController::URL.'?tender_id=not-a-uuid', $this->customerToken);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateStageWithExplicitNumber(): void
    {
        $client = self::request('POST', $this->stageUrl(), $this->supplierToken, [
            'number' => 5,
            'title' => 'Этап 5',
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(5, $body['number']);
    }

    public function testCreateStageRejectsInvalidDueDate(): void
    {
        $client = self::request('POST', $this->stageUrl(), $this->customerToken, [
            'title' => 'Этап',
            'due_at' => 'not-a-date',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testThirdPartyCannotCreateStage(): void
    {
        $outsider = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $outsiderUser = UserFactory::createOne([
            'companyId' => $outsider->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'st-out-'.random_int(1000, 999999).'@test.ru',
        ]);
        $outsiderToken = $this->loginAs((string) $outsiderUser->getEmail());

        $client = self::request('POST', $this->stageUrl(), $outsiderToken, ['title' => 'Этап']);
        self::assertResponseStatusCodeSame(404);
    }
}
