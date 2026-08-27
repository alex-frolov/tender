<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Auction\Controller\AuctionBidListController;
use App\Auction\Controller\AuctionListController;
use App\Auction\Controller\AuctionStateController;
use App\Auction\Controller\AuctionStreamController;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ContractScopeEnum;
use App\Contract\Entity\Enum\ContractStatusTransition;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Controller\TenderGetController;
use App\Tender\Controller\TenderListController;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ContractFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Видимость тендеров и аукционов между компаниями (FR-1.1.1, FR-1.5.14).
 *
 * Правило: свой тендер виден в любом статусе, чужой — по матрице стадий
 * (TenderStatusEnum::visibilityLevel):
 *   draft/withdrawn                      — только заказчику;
 *   published/accepting_bids/bidding/
 *   evaluation/awarding/contract         — участникам (открытый — всем,
 *                                          закрытый — по действующему
 *                                          многоразовому договору);
 *   closed/cancelled                     — заказчику и исполнителю (winning-заявка).
 * Видимость закупки при этом не равна видимости её содержимого: завершённые
 * лоты и ссылка на победившую заявку наружу не уходят (TenderLotView).
 * У аукциона поверх этого своя матрица (AuctionStatusEnum::visibilityLevel):
 * наружу открыта только фаза торгов, подготовка — заказчику, всё после торгов
 * — заказчику и исполнителю лота.
 * Невидимый тендер неотличим от несуществующего (404).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: findings порождает прод-код внутри HTTP-запросов — AuthMiddleware:84
 * (SELECT пользователя на каждый запрос → n-plus-one/query-in-loop в
 * мульти-запросных сценариях) и visibility-подзапросы ContractRepository:188 /
 * BidRepository:152 (duplicate-query); прод-код менять не нужно, см.
 * docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
#[IgnoreRule('query-in-loop')]
#[IgnoreRule('duplicate-query')]
final class TenderVisibilityTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private Company $customerCompany;
    private Company $outsiderCompany;
    private string $customerToken;
    private string $outsiderToken;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:9)
        $this->customerCompany = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $this->customerCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'vis-cust-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->outsiderCompany = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $outsiderUser = UserFactory::createOne([
            'companyId' => $this->outsiderCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'vis-out-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->customerToken = $this->loginAs((string) $customerUser->getEmail());
        $this->outsiderToken = $this->loginAs((string) $outsiderUser->getEmail());
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
        return '31.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function request(string $method, string $url, string $token): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        return $client;
    }

    /**
     * @return array<mixed> распакованное тело ответа
     */
    private static function body(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * Тендер заказчика с уникальным заголовком-маркером (по нему фильтруется
     * список, чтобы не зависеть от остальных данных теста).
     */
    private function tender(AccessTypeEnum $accessType, string $marker, bool $publish): Tender
    {
        $tender = TenderFactory::createOne([
            'nmckMinor' => 10000,
            'title' => $marker,
            'customerId' => $this->customerCompany->getId(),
            'accessType' => $accessType,
        ]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 10000]);

        if ($publish) {
            $workflow = static::getContainer()->get('state_machine.tender');
            self::assertInstanceOf(WorkflowInterface::class, $workflow);
            $workflow->apply($tender, TenderStatusTransition::PUBLISH->value);
            $workflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        }
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $tender;
    }

    /**
     * Подписанный рамочный multi_use-договор между заказчиком и сторонней
     * компанией (FR-1.4.8).
     */
    private function signedContract(): Contract
    {
        $contract = ContractFactory::createOne([
            'customerId' => $this->customerCompany->getId(),
            'supplierId' => $this->outsiderCompany->getId(),
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
     * @return list<string> id тендеров в выдаче каталога по маркеру
     */
    private static function catalogIds(string $token, string $marker): array
    {
        $client = self::request('GET', TenderListController::URL.'?q='.rawurlencode($marker), $token);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);

        return array_values(array_map(
            static fn (mixed $row): string => \is_array($row) && \is_string($row['id'] ?? null) ? $row['id'] : '',
            $body['items'],
        ));
    }

    public function testOpenPublishedTenderIsVisibleToAnotherCompany(): void
    {
        $marker = 'VIS-OPEN-'.random_int(1000, 999999);
        $tender = $this->tender(AccessTypeEnum::OPEN, $marker, publish: true);

        self::assertContains((string) $tender->getId(), self::catalogIds($this->outsiderToken, $marker));

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $this->outsiderToken,
        );
        self::assertResponseStatusCodeSame(200);
        self::assertSame((string) $tender->getId(), self::body($client)['id']);
    }

    public function testDraftTenderIsVisibleOnlyToItsOwnCompany(): void
    {
        $marker = 'VIS-DRAFT-'.random_int(1000, 999999);
        $tender = $this->tender(AccessTypeEnum::OPEN, $marker, publish: false);

        // владелец видит свой черновик
        self::assertContains((string) $tender->getId(), self::catalogIds($this->customerToken, $marker));

        // сторонняя компания — нет ни в списке, ни в карточке
        self::assertNotContains((string) $tender->getId(), self::catalogIds($this->outsiderToken, $marker));

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $this->outsiderToken,
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testClosedTenderIsHiddenWithoutContract(): void
    {
        $marker = 'VIS-CLOSED-'.random_int(1000, 999999);
        $tender = $this->tender(AccessTypeEnum::CONTRACT_HOLDERS, $marker, publish: true);

        self::assertNotContains((string) $tender->getId(), self::catalogIds($this->outsiderToken, $marker));

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $this->outsiderToken,
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testClosedTenderIsVisibleWithActiveMultiUseContract(): void
    {
        $this->signedContract();
        $marker = 'VIS-CONTRACT-'.random_int(1000, 999999);
        $tender = $this->tender(AccessTypeEnum::CONTRACT_HOLDERS, $marker, publish: true);

        self::assertContains((string) $tender->getId(), self::catalogIds($this->outsiderToken, $marker));

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $this->outsiderToken,
        );
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Аукцион виден так же, как его тендер: открытый опубликованный —
     * в списке и в состоянии; черновик — ни там, ни там.
     */
    public function testAuctionVisibilityFollowsTenderVisibility(): void
    {
        $visibleTender = $this->tender(AccessTypeEnum::OPEN, 'VIS-AUC-'.random_int(1000, 999999), publish: true);
        $hiddenTender = $this->tender(AccessTypeEnum::CONTRACT_HOLDERS, 'VIS-AUC-HID-'.random_int(1000, 999999), publish: true);

        // лот №1 уже создан хелпером — аукцион вешаем на отдельный лот
        $visibleAuction = AuctionFactory::new()
            ->forTender($visibleTender, LotFactory::createOne(['tender' => $visibleTender, 'number' => 2, 'priceNetMinor' => 10000]))
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();
        $hiddenAuction = AuctionFactory::new()
            ->forTender($hiddenTender, LotFactory::createOne(['tender' => $hiddenTender, 'number' => 2, 'priceNetMinor' => 10000]))
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();

        $client = self::request('GET', AuctionListController::URL, $this->outsiderToken);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $visibleAuction->getId(), $ids);
        self::assertNotContains((string) $hiddenAuction->getId(), $ids);

        // состояние аукциона открытого тендера доступно стороннему зрителю
        $client = self::request(
            'GET',
            str_replace('{auctionId}', (string) $visibleAuction->getId(), AuctionStateController::URL),
            $this->outsiderToken,
        );
        self::assertResponseStatusCodeSame(200);

        // состояние аукциона невидимого тендера — 403
        $client = self::request(
            'GET',
            str_replace('{auctionId}', (string) $hiddenAuction->getId(), AuctionStateController::URL),
            $this->outsiderToken,
        );
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Ход торгов (история ставок и live-подписка) открыт в пределах видимости
     * закупки: сторонний зритель открытого тендера получает и историю, и
     * discovery стрима; для невидимого тендера — 403.
     */
    public function testBidHistoryAndStreamFollowTenderVisibility(): void
    {
        $visibleTender = $this->tender(AccessTypeEnum::OPEN, 'VIS-BIDS-'.random_int(1000, 999999), publish: true);
        $hiddenTender = $this->tender(AccessTypeEnum::CONTRACT_HOLDERS, 'VIS-BIDS-HID-'.random_int(1000, 999999), publish: true);

        $visibleAuction = AuctionFactory::new()
            ->forTender($visibleTender, LotFactory::createOne(['tender' => $visibleTender, 'number' => 2, 'priceNetMinor' => 10000]))
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();
        $hiddenAuction = AuctionFactory::new()
            ->forTender($hiddenTender, LotFactory::createOne(['tender' => $hiddenTender, 'number' => 2, 'priceNetMinor' => 10000]))
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();

        foreach ([AuctionBidListController::URL, AuctionStreamController::URL] as $url) {
            $client = self::request(
                'GET',
                str_replace('{auctionId}', (string) $visibleAuction->getId(), $url),
                $this->outsiderToken,
            );
            self::assertResponseStatusCodeSame(200);

            $client = self::request(
                'GET',
                str_replace('{auctionId}', (string) $hiddenAuction->getId(), $url),
                $this->outsiderToken,
            );
            self::assertResponseStatusCodeSame(403);
        }
    }

    /**
     * Постановка статуса тендера напрямую.
     *
     * Переходы workflow после приёма заявок (START_TRADE и дальше) закрыты
     * guard'ами по агрегированному статусу лотов — для теста видимости эта
     * механика лишняя: проверяется зависимость выдачи от значения статуса,
     * а не легальность перехода (её покрывает TenderStateMachineTest).
     *
     * Сущность перечитывается по id: KernelBrowser перезагружает ядро между
     * запросами, поэтому объект, созданный фабрикой до первого запроса, к этому
     * моменту уже отцеплен от текущего EntityManager и flush по нему ничего
     * бы не записал.
     */
    private static function forceStatus(Tender $tender, TenderStatusEnum $status): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $managed = $em->find(Tender::class, $tender->getId());
        self::assertInstanceOf(Tender::class, $managed);
        $managed->setStatus($status);
        $em->flush();
    }

    /**
     * Рассмотрение заявок и определение победителя — часть объявленной
     * процедуры: сторонняя компания продолжает видеть закупку и в каталоге,
     * и в карточке. Закрывается она только на завершении (closed).
     */
    #[AllowQueries(55)]
    public function testProcedureStaysVisibleToOutsidersUntilItIsClosed(): void
    {
        $marker = 'VIS-EVAL-'.random_int(1000, 999999);
        $tender = $this->tender(AccessTypeEnum::OPEN, $marker, publish: true);

        foreach ([TenderStatusEnum::EVALUATION, TenderStatusEnum::AWARDING, TenderStatusEnum::CONTRACT] as $status) {
            self::forceStatus($tender, $status);
            self::assertContains(
                (string) $tender->getId(),
                self::catalogIds($this->outsiderToken, $marker),
                $status->value.': закупка должна оставаться в каталоге постороннего',
            );

            self::request(
                'GET',
                str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
                $this->outsiderToken,
            );
            self::assertResponseStatusCodeSame(200);
        }

        // Завершённая закупка — только заказчику и исполнителю.
        self::forceStatus($tender, TenderStatusEnum::CLOSED);
        self::assertNotContains((string) $tender->getId(), self::catalogIds($this->outsiderToken, $marker));
        self::assertContains((string) $tender->getId(), self::catalogIds($this->customerToken, $marker));

        self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $this->outsiderToken,
        );
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Завершённую закупку видят заказчик и исполнитель: победитель — по
     * winning-заявке, посторонний — 404, хотя он видел ту же закупку на торгах.
     */
    public function testFinishedTenderIsVisibleOnlyToCustomerAndWinner(): void
    {
        $marker = 'VIS-CLOSED-'.random_int(1000, 999999);
        $tender = $this->tender(AccessTypeEnum::OPEN, $marker, publish: true);

        // победитель — сторонняя компания ($this->outsiderCompany), проигравший — третья
        $loserToken = $this->supplierToken('vis-loser');

        BidFactory::new()
            ->with(['tenderId' => $tender->getId(), 'tenantId' => $this->customerCompany->getId(), 'supplierId' => $this->outsiderCompany->getId()])
            ->winning()
            ->create();

        self::forceStatus($tender, TenderStatusEnum::CLOSED);

        // исполнитель видит и в каталоге, и в карточке
        self::assertContains((string) $tender->getId(), self::catalogIds($this->outsiderToken, $marker));
        self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $this->outsiderToken,
        );
        self::assertResponseStatusCodeSame(200);

        // заказчик видит всегда
        self::assertContains((string) $tender->getId(), self::catalogIds($this->customerToken, $marker));

        // посторонний (проигравший) — уже нет
        self::assertNotContains((string) $tender->getId(), self::catalogIds($loserToken, $marker));
        self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $loserToken,
        );
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Видимость закупки ≠ видимость её содержимого (FR-1.5.14): на стадии
     * awarding посторонний видит карточку, но не то, кто выиграл лот, —
     * winner_bid_id маскируется. Заказчику и исполнителю он виден.
     */
    public function testWinnerIsNotRevealedToOutsiders(): void
    {
        $marker = 'VIS-WINNER-'.random_int(1000, 999999);
        $tender = $this->tender(AccessTypeEnum::OPEN, $marker, publish: true);
        $lot = $tender->getLots()->first();
        self::assertInstanceOf(Lot::class, $lot);

        $winningBid = BidFactory::new()
            ->with([
                'tenderId' => $tender->getId(),
                'lotId' => $lot->getId(),
                'tenantId' => $this->customerCompany->getId(),
                'supplierId' => $this->outsiderCompany->getId(),
            ])
            ->winning()
            ->create();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $lot->setWinnerBid($winningBid->getId());
        $em->flush();

        self::forceStatus($tender, TenderStatusEnum::AWARDING);

        $loserToken = $this->supplierToken('vis-winner-loser');
        self::assertNull(self::lotField($loserToken, $tender, 'winner_bid_id'));
        self::assertSame((string) $winningBid->getId(), self::lotField($this->customerToken, $tender, 'winner_bid_id'));
        self::assertSame((string) $winningBid->getId(), self::lotField($this->outsiderToken, $tender, 'winner_bid_id'));
    }

    /**
     * Завершённый лот внутри ещё видимой закупки (статус тендера — «бутылочное
     * горлышко» лотов) наружу не показывается: его видят заказчик
     * и исполнитель этого лота.
     */
    public function testClosedLotIsHiddenFromOutsidersInsideVisibleTender(): void
    {
        $marker = 'VIS-LOT-'.random_int(1000, 999999);
        $tender = $this->tender(AccessTypeEnum::OPEN, $marker, publish: true);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $openLot = $tender->getLots()->first();
        self::assertInstanceOf(Lot::class, $openLot);

        $closedLot = LotFactory::createOne(['tender' => $tender, 'number' => 2, 'priceNetMinor' => 10000]);
        $closedLot->setStatus(LotStatusEnum::CLOSED);

        BidFactory::new()
            ->with([
                'tenderId' => $tender->getId(),
                'lotId' => $closedLot->getId(),
                'tenantId' => $this->customerCompany->getId(),
                'supplierId' => $this->outsiderCompany->getId(),
            ])
            ->winning()
            ->create();
        $em->flush();

        $loserToken = $this->supplierToken('vis-lot-loser');

        // посторонний видит только незакрытый лот
        self::assertSame([(string) $openLot->getId()], self::lotIds($loserToken, $tender));
        // заказчик — оба
        self::assertCount(2, self::lotIds($this->customerToken, $tender));
        // исполнитель закрытого лота — тоже оба
        self::assertCount(2, self::lotIds($this->outsiderToken, $tender));
    }

    /**
     * Поставщик из отдельной компании (проигравший/посторонний) с токеном.
     */
    private function supplierToken(string $prefix): string
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => $prefix.'-'.random_int(1000, 999999).'@test.ru',
        ]);

        return $this->loginAs((string) $user->getEmail());
    }

    /**
     * @return list<string> id лотов в карточке тендера глазами зрителя
     */
    private static function lotIds(string $token, Tender $tender): array
    {
        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $token,
        );
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['lots']);

        return array_values(array_map(
            static fn (mixed $lot): string => \is_array($lot) && \is_string($lot['id'] ?? null) ? $lot['id'] : '',
            $body['lots'],
        ));
    }

    /**
     * Поле первого лота карточки тендера глазами зрителя.
     */
    private static function lotField(string $token, Tender $tender, string $field): mixed
    {
        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $token,
        );
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['lots']);
        self::assertArrayHasKey(0, $body['lots']);
        self::assertIsArray($body['lots'][0]);

        return $body['lots'][0][$field] ?? null;
    }

    /**
     * Аукцион до старта торгов — подготовка заказчика: сторонняя компания не
     * видит его ни в списке, ни в состоянии, хотя сам тендер ей виден.
     */
    public function testAuctionInPreparationIsHiddenFromOutsiders(): void
    {
        $tender = $this->tender(AccessTypeEnum::OPEN, 'VIS-AUC-PREP-'.random_int(1000, 999999), publish: true);

        $auction = AuctionFactory::new()
            ->forTender($tender, LotFactory::createOne(['tender' => $tender, 'number' => 2, 'priceNetMinor' => 10000]))
            ->with(['status' => AuctionStatusEnum::SCHEDULED])
            ->create();

        $client = self::request('GET', AuctionListController::URL, $this->outsiderToken);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        self::assertNotContains((string) $auction->getId(), array_column($body['items'], 'id'));

        $client = self::request(
            'GET',
            str_replace('{auctionId}', (string) $auction->getId(), AuctionStateController::URL),
            $this->outsiderToken,
        );
        self::assertResponseStatusCodeSame(403);

        // заказчику своя подготовка видна
        $client = self::request('GET', AuctionListController::URL, $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        self::assertContains((string) $auction->getId(), array_column($body['items'], 'id'));
    }

    /**
     * Стадия исполнения (после APPROVE) видна заказчику и исполнителю:
     * победитель лота остаётся в аукционе, проигравший — нет.
     */
    public function testAuctionInExecutionIsVisibleOnlyToCustomerAndWinner(): void
    {
        $tender = $this->tender(AccessTypeEnum::OPEN, 'VIS-AUC-EXEC-'.random_int(1000, 999999), publish: true);
        $lot = LotFactory::createOne(['tender' => $tender, 'number' => 2, 'priceNetMinor' => 10000]);

        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['status' => AuctionStatusEnum::IN_WORK])
            ->create();

        BidFactory::new()->forAuction($auction, $this->outsiderCompany->getId())->winning()->create();

        $loserToken = $this->supplierToken('vis-auc-loser');

        $client = self::request('GET', AuctionListController::URL, $this->outsiderToken);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        self::assertContains((string) $auction->getId(), array_column($body['items'], 'id'));

        $client = self::request('GET', AuctionListController::URL, $loserToken);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        self::assertNotContains((string) $auction->getId(), array_column($body['items'], 'id'));

        $client = self::request(
            'GET',
            str_replace('{auctionId}', (string) $auction->getId(), AuctionStateController::URL),
            $loserToken,
        );
        self::assertResponseStatusCodeSame(403);
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
        $body = self::body($client);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }
}
