<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Auction\AuctionService;
use App\Auction\Controller\AuctionBidController;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Bid\Controller\BidSubmitController;
use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ContractScopeEnum;
use App\Contract\Entity\Enum\ContractStatusTransition;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Controller\TenderAccessController;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ContractFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 3.5: закрытые тендеры — проверка доступа по договору (FR-1.5.14).
 *
 * - contract_holders: участвовать может только исполнитель с действующим
 *   multi_use-договором (signed/registered) с заказчиком;
 * - без договора: подача заявки → 409 access_denied; GET /tenders/{id}/access
 *   → {accessible:false, reason:contract_required};
 * - с подписанным рамочным договором: подача заявки → 201; access → ok;
 * - открытый тендер (open): access → ok для любого исполнителя;
 * - расторжение договора после допуска: ставка на аукционе → 409 access_denied
 *   (доступ проверяется и на входе в торги, а не только при подаче заявки).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ClosedTenderAccessTest extends WebTestCase
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
        return '23.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    /**
     * @return array{customer: \App\Iam\Entity\Company, supplier: \App\Iam\Entity\Company, customerToken: string, supplierToken: string}
     */
    private static function parties(): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'closed-cust-'.random_int(1000, 999999).'@test.ru',
        ]);

        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'closed-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        return [
            'customer' => $customer,
            'supplier' => $supplier,
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'supplierToken' => self::loginAs((string) $supplierUser->getEmail()),
        ];
    }

    /**
     * @param string $customerId id заказчика (string из компаний теста)
     */
    private static function closedTender(string $customerId): \App\Tender\Entity\Tender
    {
        $tender = TenderFactory::createOne([
            'nmckMinor' => 10000,
            'customerId' => \Symfony\Component\Uid\Uuid::fromString($customerId),
            'accessType' => AccessTypeEnum::CONTRACT_HOLDERS,
        ]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 10000]);

        $workflow = static::getContainer()->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $workflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $tender;
    }

    /**
     * Подписанный рамочный multi_use-договор (ФР-1.4.8) через workflow.
     */
    private static function signedContract(string $customerId, string $supplierId): Contract
    {
        $contract = ContractFactory::createOne([
            'customerId' => \Symfony\Component\Uid\Uuid::fromString($customerId),
            'supplierId' => \Symfony\Component\Uid\Uuid::fromString($supplierId),
            'scope' => ContractScopeEnum::MULTI_USE,
        ]);

        $workflow = static::getContainer()->get('state_machine.contract');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($contract, ContractStatusTransition::SEND_FOR_SIGNATURE->value);
        $contract->signParty(true, 'sign-customer');
        $contract->signParty(false, 'sign-supplier');
        $workflow->apply($contract, ContractStatusTransition::SIGN->value);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $contract;
    }

    /**
     * Единственный лот тендера: заявка подаётся на лот — у тендера с лотами
     * заявка «на тендер целиком» не принимается (допуск к торгам сверяется
     * парой тендер+лот).
     */
    private static function lotId(\App\Tender\Entity\Tender $tender): string
    {
        $lot = $tender->getLots()->first();
        self::assertNotFalse($lot);

        return (string) $lot->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private static function bidPayload(string $supplierId, string $lotId): array
    {
        return [
            'supplier_id' => $supplierId,
            'lot_id' => $lotId,
            'part1' => ['consent' => true, 'characteristics' => ['marker' => 'CLOSED-'.random_int(1000, 999999)]],
            'part2_document_ids' => [],
            'price_minor' => 9000,
            'price_basis' => 'net',
            'vat_rate' => 20,
        ];
    }

    public function testSupplierWithoutContractCannotSubmitToClosedTender(): void
    {
        self::client();
        $ctx = self::parties();
        $customerId = (string) $ctx['customer']->getId();
        $supplierId = (string) $ctx['supplier']->getId();
        $tender = self::closedTender($customerId);

        $client = self::request(
            'POST',
            str_replace('{tenderId}', (string) $tender->getId(), BidSubmitController::URL),
            $ctx['supplierToken'],
            self::bidPayload($supplierId, self::lotId($tender)),
        );
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        // Код — access_denied (реестр ErrorCode: закрытый тендер без договора),
        // а причина отказа теми же словами, что и у GET /tenders/{id}/access.
        self::assertSame('access_denied', $body['code'] ?? null);
        $detail = $body['detail'] ?? '';
        self::assertIsString($detail);
        self::assertStringContainsString('contract_required', $detail);
    }

    public function testSupplierWithSignedContractCanSubmitToClosedTender(): void
    {
        self::client();
        $ctx = self::parties();
        $customerId = (string) $ctx['customer']->getId();
        $supplierId = (string) $ctx['supplier']->getId();
        self::signedContract($customerId, $supplierId);
        $tender = self::closedTender($customerId);

        $client = self::request(
            'POST',
            str_replace('{tenderId}', (string) $tender->getId(), BidSubmitController::URL),
            $ctx['supplierToken'],
            self::bidPayload($supplierId, self::lotId($tender)),
        );
        self::assertResponseStatusCodeSame(201);
        $bid = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($bid);
        self::assertIsString($bid['id']);
    }

    /**
     * Договор расторгнут уже после допуска заявки: участник остаётся допущенным
     * (заявку никто не отзывал), но права участвовать в закрытой процедуре у
     * него больше нет — ставку на аукционе не принимаем.
     */
    public function testAdmittedSupplierCannotBidAfterContractTerminated(): void
    {
        self::client();
        $ctx = self::parties();
        $customerId = (string) $ctx['customer']->getId();
        $supplierId = (string) $ctx['supplier']->getId();
        $contract = self::signedContract($customerId, $supplierId);
        $tender = self::closedTender($customerId);
        $auction = self::tradingAuction($tender, $ctx['supplier']->getId());

        $container = static::getContainer();
        $workflow = $container->get('state_machine.contract');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($contract, ContractStatusTransition::TERMINATE->value);
        $container->get(EntityManagerInterface::class)->flush();

        $client = self::request(
            'POST',
            str_replace('{auctionId}', (string) $auction->getId(), AuctionBidController::URL),
            $ctx['supplierToken'],
            ['price_minor' => 9000],
        );
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('access_denied', $body['code'] ?? null);
        $detail = $body['detail'] ?? '';
        self::assertIsString($detail);
        self::assertStringContainsString('contract_terminated', $detail);
    }

    /**
     * Аукцион на лоте закрытого тендера в TRADE с допущенной заявкой участника.
     */
    private static function tradingAuction(\App\Tender\Entity\Tender $tender, \Symfony\Component\Uid\Uuid $supplierId): Auction
    {
        $lot = $tender->getLots()->first();
        self::assertNotFalse($lot);

        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => 500,
                'stepDurationSec' => 600,
            ])
            ->create();

        $container = static::getContainer();
        $workflow = $container->get('state_machine.auction');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $auctionService = $container->get(AuctionService::class);
        self::assertInstanceOf(AuctionService::class, $auctionService);
        $workflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $auctionService->startTrading($auction);

        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();

        return $auction;
    }

    public function testAccessEndpointContractRequiredWithoutContract(): void
    {
        self::client();
        $ctx = self::parties();
        $tender = self::closedTender((string) $ctx['customer']->getId());

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderAccessController::URL),
            $ctx['supplierToken'],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertFalse($body['accessible']);
        self::assertSame('contract_required', $body['reason']);
    }

    public function testAccessEndpointOkWithSignedContract(): void
    {
        self::client();
        $ctx = self::parties();
        self::signedContract((string) $ctx['customer']->getId(), (string) $ctx['supplier']->getId());
        $tender = self::closedTender((string) $ctx['customer']->getId());

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderAccessController::URL),
            $ctx['supplierToken'],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['accessible']);
        self::assertSame('ok', $body['reason']);
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
}
