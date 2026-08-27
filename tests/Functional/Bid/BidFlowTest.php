<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bid;

use App\Bid\Controller\BidSubmitController;
use App\Bid\Controller\BidWithdrawController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\TenderLotTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 3.2: подача/отзыв/замена заявки (FR-1.2.5, AM-4).
 *
 * - POST /tenders/{tenderId}/bids: подача (201), содержимое зашифровано;
 * - повторная подача на лот до конца приёма = замена (200-семантика: тот же bid_id);
 * - POST /bids/{bidId}/withdraw: отзыв с причиной (200), статус withdrawn;
 * - подача после окончания приёма → 409; без причины → 422;
 * - права: agent → 403; чужая заявка → 404.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class BidFlowTest extends WebTestCase
{
    use TenderLotTrait;

    private const START_MINOR = 10000;

    private static ?KernelBrowser $client = null;

    private Tender $tender;
    private string $supplierToken;
    private string $supplierId;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $this->tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR, 'customerId' => $customer->getId()]);
        LotFactory::createOne(['tender' => $this->tender, 'priceNetMinor' => self::START_MINOR]);

        $container = static::getContainer();
        $workflow = $container->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($this->tender, TenderStatusTransition::PUBLISH->value);
        $workflow->apply($this->tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $container->get(EntityManagerInterface::class)->flush();
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $this->tender->getStatus());

        $supplier = $this->supplier();
        $this->supplierToken = $supplier['token'];
        $this->supplierId = $supplier['supplierId'];
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
        return '18.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * @param array<mixed>|null $data
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

    /**
     * Подтверждённая компания-исполнитель + её admin-пользователь.
     *
     * @return array{token: string, supplierId: string}
     */
    private function supplier(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'supplier-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['token' => $this->loginAs((string) $user->getEmail()), 'supplierId' => (string) $company->getId()];
    }

    private static function submitUrl(string $tenderId): string
    {
        return str_replace('{tenderId}', $tenderId, BidSubmitController::URL);
    }

    private static function withdrawUrl(string $bidId): string
    {
        return str_replace('{bidId}', $bidId, BidWithdrawController::URL);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bidPayload(string $supplierId, string $lotId, int $price = 900000): array
    {
        return [
            'supplier_id' => $supplierId,
            'lot_id' => $lotId,
            'part1' => ['consent' => true, 'characteristics' => ['marker' => 'SECRET-'.random_int(1000, 999999)]],
            'part2_document_ids' => ['11111111-1111-4111-8111-111111111111'],
            'price_minor' => $price,
            'price_basis' => 'net',
            'vat_rate' => 20,
        ];
    }

    public function testSubmitReplaceAndWithdrawFlow(): void
    {
        $url = self::submitUrl((string) $this->tender->getId());

        // 1. подача заявки (FR-1.2.1): 201, статус submitted, содержимое скрыто
        $client = self::request('POST', $url, $this->supplierToken, self::bidPayload($this->supplierId, self::firstLotId($this->tender)));
        self::assertResponseStatusCodeSame(201);
        $bid = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($bid);
        self::assertSame('submitted', $bid['status']);
        self::assertSame($this->supplierId, $bid['supplier_id']);
        self::assertArrayNotHasKey('part1', $bid);
        self::assertArrayNotHasKey('price_minor', $bid);
        $bidId = $bid['id'];
        self::assertIsString($bidId);

        // 2. повторная подача на тот же лот до конца приёма = замена (FR-1.2.5)
        $client = self::request('POST', $url, $this->supplierToken, self::bidPayload($this->supplierId, self::firstLotId($this->tender), 850000));
        self::assertResponseStatusCodeSame(201);
        $replaced = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($replaced);
        self::assertSame($bidId, $replaced['id']);
        self::assertSame('submitted', $replaced['status']);

        // 3. отзыв с причиной (FR-1.2.5, AM-4): 200, статус withdrawn
        $client = self::request('POST', self::withdrawUrl($bidId), $this->supplierToken, ['reason' => 'Сняли заявку']);
        self::assertResponseStatusCodeSame(200);
        $withdrawn = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($withdrawn);
        self::assertSame($bidId, $withdrawn['id']);
        self::assertSame('withdrawn', $withdrawn['status']);
        self::assertSame('Сняли заявку', $withdrawn['decision_reason']);
    }

    public function testSubmitAfterAcceptanceClosedReturns409(): void
    {
        // закрыть приём (отмена тендера — терминальна)
        $workflow = static::getContainer()->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($this->tender, TenderStatusTransition::CANCEL->value);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client = self::request('POST', self::submitUrl((string) $this->tender->getId()), $this->supplierToken, self::bidPayload($this->supplierId, self::firstLotId($this->tender)));
        self::assertResponseStatusCodeSame(409);
    }

    public function testWithdrawWithoutReasonReturns422(): void
    {
        $client = self::request('POST', self::submitUrl((string) $this->tender->getId()), $this->supplierToken, self::bidPayload($this->supplierId, self::firstLotId($this->tender)));
        self::assertResponseStatusCodeSame(201);
        $bid = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($bid);
        self::assertIsString($bid['id']);

        $client = self::request('POST', self::withdrawUrl($bid['id']), $this->supplierToken, []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testWithdrawOthersBidReturns404(): void
    {
        $other = $this->supplier();

        $client = self::request('POST', self::submitUrl((string) $this->tender->getId()), $this->supplierToken, self::bidPayload($this->supplierId, self::firstLotId($this->tender)));
        self::assertResponseStatusCodeSame(201);
        $bid = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($bid);
        self::assertIsString($bid['id']);

        $client = self::request('POST', self::withdrawUrl($bid['id']), $other['token'], ['reason' => 'Попытка чужого отзыва']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAgentCannotSubmitReturns403(): void
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $agent = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $token = $this->loginAs((string) $agent->getEmail());

        $client = self::request('POST', self::submitUrl((string) $this->tender->getId()), $token, self::bidPayload((string) $company->getId(), self::firstLotId($this->tender)));
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = self::request('POST', self::submitUrl('00000000-0000-0000-0000-000000000000'), '', []);
        self::assertResponseStatusCodeSame(401);
    }
}
