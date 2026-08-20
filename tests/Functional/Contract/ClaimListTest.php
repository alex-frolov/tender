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

    /**
     * @return array{customerToken: string, supplierToken: string, own: Claim, foreign: Claim, contractId: Uuid}
     */
    private static function context(): array
    {
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

        $contractId = Uuid::v4();
        $own = self::claim($customer->getId(), $customer->getId(), $supplier->getId(), $contractId);
        // Претензия между двумя посторонними компаниями — не должна попадать в выдачу.
        $foreignCompany = Uuid::v4();
        $foreign = self::claim($foreignCompany, $foreignCompany, Uuid::v4(), Uuid::v4());

        return [
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'supplierToken' => self::loginAs((string) $supplierUser->getEmail()),
            'own' => $own,
            'foreign' => $foreign,
            'contractId' => $contractId,
        ];
    }

    public function testCustomerSeesOwnClaimsAndNotForeign(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(ClaimListController::URL, $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertArrayHasKey('next_cursor', $body);

        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $ctx['own']->getId(), $ids);
        self::assertNotContains((string) $ctx['foreign']->getId(), $ids);

        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertArrayHasKey('contract_id', $item);
        self::assertArrayHasKey('amount_minor', $item);
        self::assertArrayHasKey('status', $item);
        self::assertArrayHasKey('created_at', $item);
    }

    public function testPerformerSeesClaimAgainstIt(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(ClaimListController::URL, $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        // Исполнитель видит претензию к себе: разбирательство двустороннее.
        self::assertContains((string) $ctx['own']->getId(), $ids);
    }

    public function testFilterByContractNarrowsList(): void
    {
        self::client();
        $ctx = self::context();
        // Вторая претензия той же компании, но по другому договору.
        $other = self::claim(
            $ctx['own']->getTenantId(),
            $ctx['own']->getCustomerId(),
            $ctx['own']->getSupplierId(),
            Uuid::v4(),
        );

        $client = self::request(
            ClaimListController::URL.'?contract_id='.$ctx['contractId'],
            $ctx['customerToken'],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $ctx['own']->getId(), $ids);
        self::assertNotContains((string) $other->getId(), $ids);
    }

    public function testInvalidContractIdIsRejected(): void
    {
        self::client();
        $ctx = self::context();

        self::request(ClaimListController::URL.'?contract_id=not-a-uuid', $ctx['customerToken']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthorized(): void
    {
        self::client();
        self::request(ClaimListController::URL, null);
        self::assertResponseStatusCodeSame(401);
    }
}
