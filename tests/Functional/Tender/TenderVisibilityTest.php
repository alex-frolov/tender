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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
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
 */
final class TenderVisibilityTest extends WebTestCase
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
     * Заказчик (владелец тендеров) и сторонний поставщик из другой компании.
     *
     * @return array{customer: \App\Iam\Entity\Company, outsider: \App\Iam\Entity\Company, customerToken: string, outsiderToken: string}
     */
    private static function parties(): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'vis-cust-'.random_int(1000, 999999).'@test.ru',
        ]);

        $outsider = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $outsiderUser = UserFactory::createOne([
            'companyId' => $outsider->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'vis-out-'.random_int(1000, 999999).'@test.ru',
        ]);

        return [
            'customer' => $customer,
            'outsider' => $outsider,
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'outsiderToken' => self::loginAs((string) $outsiderUser->getEmail()),
        ];
    }

    /**
     * Тендер заказчика с уникальным заголовком-маркером (по нему фильтруется
     * список, чтобы не зависеть от остальных данных теста).
     */
    private static function tender(Uuid $customerId, AccessTypeEnum $accessType, string $marker, bool $publish): Tender
    {
        $tender = TenderFactory::createOne([
            'nmckMinor' => 10000,
            'title' => $marker,
            'customerId' => $customerId,
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
     * Подписанный рамочный multi_use-договор между сторонами (FR-1.4.8).
     */
    private static function signedContract(Uuid $customerId, Uuid $supplierId): Contract
    {
        $contract = ContractFactory::createOne([
            'customerId' => $customerId,
            'supplierId' => $supplierId,
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
        self::client();
        $ctx = self::parties();
        $marker = 'VIS-OPEN-'.random_int(1000, 999999);
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, $marker, publish: true);

        self::assertContains((string) $tender->getId(), self::catalogIds($ctx['outsiderToken'], $marker));

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $ctx['outsiderToken'],
        );
        self::assertResponseStatusCodeSame(200);
        self::assertSame((string) $tender->getId(), self::body($client)['id']);
    }

    public function testDraftTenderIsVisibleOnlyToItsOwnCompany(): void
    {
        self::client();
        $ctx = self::parties();
        $marker = 'VIS-DRAFT-'.random_int(1000, 999999);
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, $marker, publish: false);

        // владелец видит свой черновик
        self::assertContains((string) $tender->getId(), self::catalogIds($ctx['customerToken'], $marker));

        // сторонняя компания — нет ни в списке, ни в карточке
        self::assertNotContains((string) $tender->getId(), self::catalogIds($ctx['outsiderToken'], $marker));

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $ctx['outsiderToken'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testClosedTenderIsHiddenWithoutContract(): void
    {
        self::client();
        $ctx = self::parties();
        $marker = 'VIS-CLOSED-'.random_int(1000, 999999);
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::CONTRACT_HOLDERS, $marker, publish: true);

        self::assertNotContains((string) $tender->getId(), self::catalogIds($ctx['outsiderToken'], $marker));

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $ctx['outsiderToken'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testClosedTenderIsVisibleWithActiveMultiUseContract(): void
    {
        self::client();
        $ctx = self::parties();
        self::signedContract($ctx['customer']->getId(), $ctx['outsider']->getId());
        $marker = 'VIS-CONTRACT-'.random_int(1000, 999999);
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::CONTRACT_HOLDERS, $marker, publish: true);

        self::assertContains((string) $tender->getId(), self::catalogIds($ctx['outsiderToken'], $marker));

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $ctx['outsiderToken'],
        );
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Аукцион виден так же, как его тендер: открытый опубликованный —
     * в списке и в состоянии; черновик — ни там, ни там.
     */
    public function testAuctionVisibilityFollowsTenderVisibility(): void
    {
        self::client();
        $ctx = self::parties();
        $visibleTender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, 'VIS-AUC-'.random_int(1000, 999999), publish: true);
        $hiddenTender = self::tender($ctx['customer']->getId(), AccessTypeEnum::CONTRACT_HOLDERS, 'VIS-AUC-HID-'.random_int(1000, 999999), publish: true);

        // лот №1 уже создан хелпером — аукцион вешаем на отдельный лот
        $visibleAuction = AuctionFactory::new()
            ->forTender($visibleTender, LotFactory::createOne(['tender' => $visibleTender, 'number' => 2, 'priceNetMinor' => 10000]))
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();
        $hiddenAuction = AuctionFactory::new()
            ->forTender($hiddenTender, LotFactory::createOne(['tender' => $hiddenTender, 'number' => 2, 'priceNetMinor' => 10000]))
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();

        $client = self::request('GET', AuctionListController::URL, $ctx['outsiderToken']);
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
            $ctx['outsiderToken'],
        );
        self::assertResponseStatusCodeSame(200);

        // состояние аукциона невидимого тендера — 403
        $client = self::request(
            'GET',
            str_replace('{auctionId}', (string) $hiddenAuction->getId(), AuctionStateController::URL),
            $ctx['outsiderToken'],
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
        self::client();
        $ctx = self::parties();
        $visibleTender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, 'VIS-BIDS-'.random_int(1000, 999999), publish: true);
        $hiddenTender = self::tender($ctx['customer']->getId(), AccessTypeEnum::CONTRACT_HOLDERS, 'VIS-BIDS-HID-'.random_int(1000, 999999), publish: true);

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
                $ctx['outsiderToken'],
            );
            self::assertResponseStatusCodeSame(200);

            $client = self::request(
                'GET',
                str_replace('{auctionId}', (string) $hiddenAuction->getId(), $url),
                $ctx['outsiderToken'],
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
    public function testProcedureStaysVisibleToOutsidersUntilItIsClosed(): void
    {
        self::client();
        $ctx = self::parties();
        $marker = 'VIS-EVAL-'.random_int(1000, 999999);
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, $marker, publish: true);

        foreach ([TenderStatusEnum::EVALUATION, TenderStatusEnum::AWARDING, TenderStatusEnum::CONTRACT] as $status) {
            self::forceStatus($tender, $status);
            self::assertContains(
                (string) $tender->getId(),
                self::catalogIds($ctx['outsiderToken'], $marker),
                $status->value.': закупка должна оставаться в каталоге постороннего',
            );

            self::request(
                'GET',
                str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
                $ctx['outsiderToken'],
            );
            self::assertResponseStatusCodeSame(200);
        }

        // Завершённая закупка — только заказчику и исполнителю.
        self::forceStatus($tender, TenderStatusEnum::CLOSED);
        self::assertNotContains((string) $tender->getId(), self::catalogIds($ctx['outsiderToken'], $marker));
        self::assertContains((string) $tender->getId(), self::catalogIds($ctx['customerToken'], $marker));

        self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $ctx['outsiderToken'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Завершённую закупку видят заказчик и исполнитель: победитель — по
     * winning-заявке, посторонний — 404, хотя он видел ту же закупку на торгах.
     */
    public function testFinishedTenderIsVisibleOnlyToCustomerAndWinner(): void
    {
        self::client();
        $ctx = self::parties();
        $marker = 'VIS-CLOSED-'.random_int(1000, 999999);
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, $marker, publish: true);

        // победитель — сторонняя компания $ctx['outsider'], проигравший — третья
        $loserToken = self::supplierToken('vis-loser');

        BidFactory::new()
            ->with(['tenderId' => $tender->getId(), 'tenantId' => $ctx['customer']->getId(), 'supplierId' => $ctx['outsider']->getId()])
            ->winning()
            ->create();

        self::forceStatus($tender, TenderStatusEnum::CLOSED);

        // исполнитель видит и в каталоге, и в карточке
        self::assertContains((string) $tender->getId(), self::catalogIds($ctx['outsiderToken'], $marker));
        self::request(
            'GET',
            str_replace('{tenderId}', (string) $tender->getId(), TenderGetController::URL),
            $ctx['outsiderToken'],
        );
        self::assertResponseStatusCodeSame(200);

        // заказчик видит всегда
        self::assertContains((string) $tender->getId(), self::catalogIds($ctx['customerToken'], $marker));

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
        self::client();
        $ctx = self::parties();
        $marker = 'VIS-WINNER-'.random_int(1000, 999999);
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, $marker, publish: true);
        $lot = $tender->getLots()->first();
        self::assertInstanceOf(Lot::class, $lot);

        $winningBid = BidFactory::new()
            ->with([
                'tenderId' => $tender->getId(),
                'lotId' => $lot->getId(),
                'tenantId' => $ctx['customer']->getId(),
                'supplierId' => $ctx['outsider']->getId(),
            ])
            ->winning()
            ->create();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $lot->setWinnerBid($winningBid->getId());
        $em->flush();

        self::forceStatus($tender, TenderStatusEnum::AWARDING);

        $loserToken = self::supplierToken('vis-winner-loser');
        self::assertNull(self::lotField($loserToken, $tender, 'winner_bid_id'));
        self::assertSame((string) $winningBid->getId(), self::lotField($ctx['customerToken'], $tender, 'winner_bid_id'));
        self::assertSame((string) $winningBid->getId(), self::lotField($ctx['outsiderToken'], $tender, 'winner_bid_id'));
    }

    /**
     * Завершённый лот внутри ещё видимой закупки (статус тендера — «бутылочное
     * горлышко» лотов) наружу не показывается: его видят заказчик
     * и исполнитель этого лота.
     */
    public function testClosedLotIsHiddenFromOutsidersInsideVisibleTender(): void
    {
        self::client();
        $ctx = self::parties();
        $marker = 'VIS-LOT-'.random_int(1000, 999999);
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, $marker, publish: true);

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
                'tenantId' => $ctx['customer']->getId(),
                'supplierId' => $ctx['outsider']->getId(),
            ])
            ->winning()
            ->create();
        $em->flush();

        $loserToken = self::supplierToken('vis-lot-loser');

        // посторонний видит только незакрытый лот
        self::assertSame([(string) $openLot->getId()], self::lotIds($loserToken, $tender));
        // заказчик — оба
        self::assertCount(2, self::lotIds($ctx['customerToken'], $tender));
        // исполнитель закрытого лота — тоже оба
        self::assertCount(2, self::lotIds($ctx['outsiderToken'], $tender));
    }

    /**
     * Поставщик из отдельной компании (проигравший/посторонний) с токеном.
     */
    private static function supplierToken(string $prefix): string
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => $prefix.'-'.random_int(1000, 999999).'@test.ru',
        ]);

        return self::loginAs((string) $user->getEmail());
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
        self::client();
        $ctx = self::parties();
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, 'VIS-AUC-PREP-'.random_int(1000, 999999), publish: true);

        $auction = AuctionFactory::new()
            ->forTender($tender, LotFactory::createOne(['tender' => $tender, 'number' => 2, 'priceNetMinor' => 10000]))
            ->with(['status' => AuctionStatusEnum::SCHEDULED])
            ->create();

        $client = self::request('GET', AuctionListController::URL, $ctx['outsiderToken']);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        self::assertNotContains((string) $auction->getId(), array_column($body['items'], 'id'));

        $client = self::request(
            'GET',
            str_replace('{auctionId}', (string) $auction->getId(), AuctionStateController::URL),
            $ctx['outsiderToken'],
        );
        self::assertResponseStatusCodeSame(403);

        // заказчику своя подготовка видна
        $client = self::request('GET', AuctionListController::URL, $ctx['customerToken']);
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
        self::client();
        $ctx = self::parties();
        $tender = self::tender($ctx['customer']->getId(), AccessTypeEnum::OPEN, 'VIS-AUC-EXEC-'.random_int(1000, 999999), publish: true);
        $lot = LotFactory::createOne(['tender' => $tender, 'number' => 2, 'priceNetMinor' => 10000]);

        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['status' => AuctionStatusEnum::IN_WORK])
            ->create();

        BidFactory::new()->forAuction($auction, $ctx['outsider']->getId())->winning()->create();

        $loser = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $loserUser = UserFactory::createOne([
            'companyId' => $loser->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'vis-auc-loser-'.random_int(1000, 999999).'@test.ru',
        ]);
        $loserToken = self::loginAs((string) $loserUser->getEmail());

        $client = self::request('GET', AuctionListController::URL, $ctx['outsiderToken']);
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
        $body = self::body($client);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }
}
