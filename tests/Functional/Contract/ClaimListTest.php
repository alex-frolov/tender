<?php

declare(strict_types=1);

namespace App\Tests\Functional\Contract;

use App\Contract\Controller\ClaimListController;
use App\Contract\Entity\Claim;
use App\Contract\Entity\Enum\ClaimStageEnum;
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
 * GET /claims (FR-1.4.5): претензии компании актора.
 *
 * - видит обе стороны разбирательства: и заказчик, и исполнитель;
 * - чужие претензии не отдаются (party-фильтрация в ClaimService);
 * - фильтр ?contract_id= сужает выборку;
 * - 401 без токена.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ClaimListTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private string $customerToken;
    private string $supplierToken;
    private Claim $own;
    private Claim $foreign;
    private Uuid $contractId;

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
            'email' => 'cl-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cl-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->contractId = Uuid::v4();
        $this->own = self::claim($customer->getId(), $customer->getId(), $supplier->getId(), $this->contractId);
        // Претензия между двумя посторонними компаниями — не должна попадать в выдачу.
        $foreignCompany = Uuid::v4();
        $this->foreign = self::claim($foreignCompany, $foreignCompany, Uuid::v4(), Uuid::v4());

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
        return '77.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private static function claim(Uuid $tenantId, Uuid $customerId, Uuid $supplierId, Uuid $contractId): Claim
    {
        $claim = new Claim(
            tenantId: $tenantId,
            contractId: $contractId,
            supplierId: $supplierId,
            customerId: $customerId,
            stage: ClaimStageEnum::IN_WORK,
            reason: 'Просрочка этапа',
            amountMinor: 150_000,
            description: 'Сроки сорваны на две недели',
        );
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($claim);
        $em->flush();

        return $claim;
    }

    public function testCustomerSeesOwnClaimsAndNotForeign(): void
    {
        $client = self::request(ClaimListController::URL, $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertArrayHasKey('next_cursor', $body);

        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $this->own->getId(), $ids);
        self::assertNotContains((string) $this->foreign->getId(), $ids);

        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertArrayHasKey('contract_id', $item);
        self::assertArrayHasKey('amount_minor', $item);
        self::assertArrayHasKey('status', $item);
        self::assertArrayHasKey('created_at', $item);
    }

    public function testPerformerSeesClaimAgainstIt(): void
    {
        $client = self::request(ClaimListController::URL, $this->supplierToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        // Исполнитель видит претензию к себе: разбирательство двустороннее.
        self::assertContains((string) $this->own->getId(), $ids);
    }

    public function testFilterByContractNarrowsList(): void
    {
        // Вторая претензия той же компании, но по другому договору.
        $other = self::claim(
            $this->own->getTenantId(),
            $this->own->getCustomerId(),
            $this->own->getSupplierId(),
            Uuid::v4(),
        );

        $client = self::request(
            ClaimListController::URL.'?contract_id='.$this->contractId,
            $this->customerToken,
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $this->own->getId(), $ids);
        self::assertNotContains((string) $other->getId(), $ids);
    }

    public function testInvalidContractIdIsRejected(): void
    {
        self::request(ClaimListController::URL.'?contract_id=not-a-uuid', $this->customerToken);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthorized(): void
    {
        self::request(ClaimListController::URL, null);
        self::assertResponseStatusCodeSame(401);
    }
}
