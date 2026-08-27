<?php

declare(strict_types=1);

namespace App\Tests\Functional\FullCycle;

use App\Auction\AuctionService;
use App\Auction\Controller\AuctionBidController;
use App\Auction\Controller\AuctionConfirmDoneController;
use App\Auction\Controller\AuctionCreateController;
use App\Auction\Controller\AuctionMarkDoneController;
use App\Auction\Controller\AuctionScheduleController;
use App\Auction\Controller\AuctionStartWorkController;
use App\Auction\Controller\AuctionUpdateController;
use App\Auction\Controller\AuctionWinnerController;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Bid\Controller\BidQualifyController;
use App\Bid\Controller\BidSubmitController;
use App\Bid\Entity\Bid;
use App\Contract\Controller\ContractCreateController;
use App\Contract\Controller\ContractSendForSignatureController;
use App\Contract\Controller\ContractSignController;
use App\Contract\Entity\Contract;
use App\Iam\Controller\Auth\EmailVerifyController;
use App\Iam\Controller\Auth\RegisterController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\Company\CompanyVerifyController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tender\Controller\TenderCreateController;
use App\Tender\Controller\TenderPublishController;
use App\Tender\Controller\TenderRateController;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tender\Timeline\TenderTimelineAction;
use App\Tender\Timeline\TimelineMessage;
use App\Tender\Timeline\TimelineMessageHandler;
use App\Tests\Factory\ContractTypeFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Email;

/**
 * Задача 7.1: E2E полного цикла — регистрация → тендер → заявки → аукцион →
 * договор → исполнение.
 *
 * Сквозной сценарий через API (как в проде):
 * - регистрация двух компаний (заказчик/исполнитель) + подтверждение email + вход;
 * - подтверждение компаний суперадмином (FR-1.5.7);
 * - создание/публикация тендера (FR-1.1.1/1.1.4) + авто-переходы таймлайна;
 * - подача и допуск заявки (FR-1.2.1/1.2.3/1.2.4);
 * - аукцион REDUCTION(fixed): создание (POST /auctions) + правка параметров
 *   до торгов (PATCH /auctions/{id}, в т.ч. явный null-сброс) + планирование
 *   (POST /auctions/{id}/schedule, T10) + старт торгов (система) + ставка +
 *   авто-выбор победителя через API (FR-1.3.x);
 * - договор по итогам (source=tender, FR-1.4.3) + подписание обеих сторон;
 * - исполнение: start-work → mark-done → confirm-done (DONE, B2);
 * - оценка исполнения (FR-1.1.10);
 * - итоговое состояние в БД согласовано.
 *
 * Rate limit api_global в тестах = 3/мин на IP → каждый запрос с нового IP.
 *
 * QueryGuard: `n-plus-one`, `query-in-loop`, `duplicate-query` — AuthMiddleware:84
 * (SELECT пользователя на каждый HTTP-запрос), батчинг аудита (AuditService:75),
 * дубликаты — visibility-подзапросы ContractRepository:188/BidRepository:152 на
 * каждый запрос; `AllowQueries(310)` — весь сквозной E2E в одном тесте
 * (261 запрос); см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
#[IgnoreRule('query-in-loop')]
#[IgnoreRule('duplicate-query')]
final class FullCycleE2ETest extends WebTestCase
{
    private const PASSWORD = 'secret123';
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

    private static ?KernelBrowser $client = null;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры (суперадмин и справочник типов договоров) создаются в setUp →
        // QueryGuard считает их как fixtureQueries. Сам E2E-флоу — в теле теста.
        $this->admin = UserFactory::createOne([
            'email' => self::uniqueEmail(),
            'name' => 'Суперадмин',
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'password' => self::PASSWORD,
        ]);
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
        return '51.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function uniqueEmail(): string
    {
        return \sprintf('fullcycle-%s@test.ru', bin2hex(random_bytes(4)));
    }

    private static function futureDateTime(): string
    {
        return (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function post(string $url, array $data): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], self::json($data));

        return $client;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            throw new \LogicException('Cannot encode JSON');
        }

        return $json;
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
            null === $data ? '' : self::json($data),
        );

        return $client;
    }

    /**
     * Письма, переданные последним запросом в очередь messenger (канал `emails`).
     * Ядро перезапускается между запросами, поэтому логгер хранит события
     * только последнего запроса.
     *
     * @return list<Email>
     */
    private static function sentEmails(): array
    {
        $logger = self::getContainer()->get('mailer.message_logger_listener');
        self::assertInstanceOf(MessageLoggerListener::class, $logger);

        $emails = [];
        foreach ($logger->getEvents()->getEvents() as $event) {
            if (!$event->isQueued()) {
                continue;
            }
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $emails[] = $message;
            }
        }

        return $emails;
    }

    /**
     * Сырой токен подтверждения из тела письма.
     *
     * @param list<Email> $emails
     */
    private static function lastToken(array $emails): string
    {
        self::assertNotEmpty($emails);
        $last = end($emails);
        self::assertInstanceOf(Email::class, $last);
        $body = $last->getTextBody();
        self::assertIsString($body);

        $matches = [];
        if (1 !== preg_match('/token=([a-f0-9]{64})/', $body, $matches)) {
            self::fail('Verification token not found in email body');
        }

        return $matches[1];
    }

    /**
     * Регистрация компании через API + подтверждение email.
     *
     * @return array{company_id: string, user_id: string, email: string}
     */
    private static function registerAndVerify(string $orgType, string $companyName): array
    {
        $email = self::uniqueEmail();
        $client = self::post(RegisterController::URL, [
            'company_name' => $companyName,
            'inn' => (string) random_int(1000000000, 9999999999),
            'org_type' => $orgType,
            'email' => $email,
            'password' => self::PASSWORD,
            'user_name' => 'Иван '.$orgType,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('pending', $body['verification_status']);
        $companyId = $body['company_id'] ?? null;
        $userId = $body['user_id'] ?? null;
        self::assertIsString($companyId);
        self::assertIsString($userId);

        // подтверждение email по токену из письма (FR-1.5.5)
        $token = self::lastToken(self::sentEmails());
        $client = self::post(EmailVerifyController::URL, ['token' => $token]);
        self::assertResponseStatusCodeSame(200);
        $verified = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($verified);
        self::assertTrue($verified['email_verified'] ?? false);

        return ['company_id' => $companyId, 'user_id' => $userId, 'email' => $email];
    }

    /**
     * Вход через API (FR-1.5.3).
     */
    private static function login(string $email): string
    {
        $client = self::post(TokenController::URL, ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $token = $body['access_token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /**
     * Подтверждение компании суперадмином (FR-1.5.7).
     */
    private static function approveCompany(string $adminToken, string $companyId): void
    {
        $url = str_replace('{companyId}', $companyId, CompanyVerifyController::URL);
        $client = self::request('POST', $url, $adminToken, ['action' => 'approve']);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Обработка отложенной задачи таймлайна (симуляция worker).
     */
    private static function processTimeline(string $tenderId, string $action): void
    {
        $handler = self::getContainer()->get(TimelineMessageHandler::class);
        self::assertInstanceOf(TimelineMessageHandler::class, $handler);
        $handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: $tenderId,
            action: $action,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function createTenderPayload(string $customerId): array
    {
        return [
            'title' => 'E2E полный цикл закупки',
            'description' => 'Сквозной сценарий: заявки, аукцион, договор, исполнение',
            'procedure_type' => 'auction',
            'law_type' => 'commercial',
            'nmck_minor' => self::START_MINOR,
            'no_start_price' => false,
            'currency' => 'RUB',
            'vat_rate' => 20,
            'price_basis' => 'net',
            'customer_id' => $customerId,
            'region' => 'Москва',
            'access_type' => 'open',
            'lots' => [
                ['title' => 'ИТ-оборудование', 'price_net_minor' => self::START_MINOR],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bidPayload(string $supplierId, string $lotId): array
    {
        return [
            'supplier_id' => $supplierId,
            'lot_id' => $lotId,
            'part1' => ['consent' => true, 'characteristics' => ['marker' => 'FULL-CYCLE']],
            'part2_document_ids' => [],
            'price_minor' => self::START_MINOR,
            'price_basis' => 'net',
            'vat_rate' => 20,
        ];
    }

    #[AllowQueries(310)]
    public function testFullCycleRegistrationTenderBidsAuctionContractExecution(): void
    {
        // ── 1. Регистрация двух компаний + подтверждение email (FR-1.5.4/1.5.5)
        $customer = self::registerAndVerify('customer', 'ООО E2E Заказчик');
        $supplier = self::registerAndVerify('supplier', 'ООО E2E Исполнитель');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $customerCompany = $em->getRepository(Company::class)->find($customer['company_id']);
        self::assertInstanceOf(Company::class, $customerCompany);
        self::assertSame(CompanyStatusEnum::PENDING, $customerCompany->getVerificationStatus());

        // ── 2. Подтверждение компаний суперадмином (FR-1.5.7)
        $adminToken = self::login((string) $this->admin->getEmail());
        self::approveCompany($adminToken, $customer['company_id']);
        self::approveCompany($adminToken, $supplier['company_id']);

        $em->clear();
        $customerCompany = $em->getRepository(Company::class)->find($customer['company_id']);
        self::assertInstanceOf(Company::class, $customerCompany);
        self::assertTrue($customerCompany->isActive());

        // ── 3. Вход (FR-1.5.3)
        $customerToken = self::login($customer['email']);
        $supplierToken = self::login($supplier['email']);

        // ── 4. Тендер: создание (FR-1.1.1) + публикация (FR-1.1.4)
        $client = self::request('POST', TenderCreateController::URL, $customerToken, self::createTenderPayload($customer['company_id']));
        self::assertResponseStatusCodeSame(201);
        $tenderBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($tenderBody);
        self::assertSame('draft', $tenderBody['status']);
        $tenderId = $tenderBody['id'];
        self::assertIsString($tenderId);
        $lots = $tenderBody['lots'];
        self::assertIsArray($lots);
        self::assertArrayHasKey(0, $lots);
        self::assertIsArray($lots[0]);
        $lotId = $lots[0]['id'] ?? null;
        self::assertIsString($lotId);

        $publishUrl = str_replace('{tenderId}', $tenderId, TenderPublishController::URL);
        $client = self::request('POST', $publishUrl, $customerToken);
        self::assertResponseStatusCodeSame(200);
        $published = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($published);
        self::assertSame('published', $published['status']);
        self::assertIsArray($published['timeline']);
        self::assertArrayHasKey('bids_start', $published['timeline']);
        self::assertArrayHasKey('bids_end', $published['timeline']);

        // ── 5. Авто-переход published → accepting_bids (таймлайн, FR-1.1.4)
        self::processTimeline($tenderId, TenderTimelineAction::START_BID_ACCEPTANCE->value);
        $em->clear();
        $tender = $em->getRepository(Tender::class)->find($tenderId);
        self::assertInstanceOf(Tender::class, $tender);
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $tender->getStatus());

        // ── 6. Подача заявки (FR-1.2.1)
        $bidUrl = str_replace('{tenderId}', $tenderId, BidSubmitController::URL);
        $client = self::request('POST', $bidUrl, $supplierToken, self::bidPayload($supplier['company_id'], $lotId));
        self::assertResponseStatusCodeSame(201);
        $bidBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($bidBody);
        self::assertSame('submitted', $bidBody['status']);
        self::assertArrayNotHasKey('part1', $bidBody, 'содержимое скрыто до вскрытия (FR-1.2.2)');
        $bidId = $bidBody['id'];
        self::assertIsString($bidId);

        // ── 7. Авто-вскрытие (таймлайн, FR-1.2.3)
        self::processTimeline($tenderId, TenderTimelineAction::OPEN_BIDS->value);
        $em->clear();
        $tender = $em->getRepository(Tender::class)->find($tenderId);
        self::assertInstanceOf(Tender::class, $tender);
        self::assertNotNull($tender->getBidsOpenedAt(), 'bids_opened_at зафиксирован при вскрытии');

        // ── 8. Допуск заявки (FR-1.2.4)
        $qualifyUrl = str_replace('{bidId}', $bidId, BidQualifyController::URL);
        $client = self::request('POST', $qualifyUrl, $customerToken, [
            'decision' => 'admit',
            'reason' => 'Документы в порядке',
        ]);
        self::assertResponseStatusCodeSame(200);
        $qualified = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($qualified);
        self::assertSame('admitted', $qualified['status']);

        // ── 9. Аукцион REDUCTION(fixed): создание (POST /auctions, FR-1.3) +
        //        планирование (POST /auctions/{id}/schedule, T10) + старт торгов
        //        (системное действие, FR-1.3.1/1.3.7)
        $client = self::request('POST', AuctionCreateController::URL, $customerToken, [
            'lot_id' => $lotId,
            'type' => 'reduction',
            'step_mode' => 'fixed',
            'bid_step_minor' => self::STEP_MINOR,
            'step_duration_sec' => 600,
        ]);
        self::assertResponseStatusCodeSame(201);
        $auctionBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($auctionBody);
        self::assertSame('new', $auctionBody['status']);
        self::assertSame(self::START_MINOR, $auctionBody['start_price_minor']);
        $auctionId = $auctionBody['id'] ?? null;
        self::assertIsString($auctionId);

        // ── 9a. Правка данных аукциона ДО торгов (PATCH /auctions/{id}, FR-1.3.1):
        //      меняем параметры, затем явно сбрасываем границу в null.
        $updateUrl = str_replace('{auctionId}', $auctionId, AuctionUpdateController::URL);
        $client = self::request('PATCH', $updateUrl, $customerToken, [
            'max_extensions' => 5,
            'step_duration_sec' => 300,
            'price_min_limit_minor' => 90_000_000,
        ]);
        self::assertResponseStatusCodeSame(200);
        $updated = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($updated);
        self::assertSame('new', $updated['status'], 'статус при правке не меняется');
        self::assertSame(5, $updated['max_extensions']);
        self::assertSame(300, $updated['step_duration_sec']);
        self::assertSame(90_000_000, $updated['price_min_limit_minor']);
        self::assertSame(self::STEP_MINOR, $updated['bid_step_minor'], 'не переданные поля не меняются');

        $client = self::request('PATCH', $updateUrl, $customerToken, ['price_min_limit_minor' => null]);
        self::assertResponseStatusCodeSame(200);
        $updated = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($updated);
        self::assertNull($updated['price_min_limit_minor'], 'явный null сбрасывает поле');
        self::assertSame(5, $updated['max_extensions']);

        $scheduleUrl = str_replace('{auctionId}', $auctionId, AuctionScheduleController::URL);
        $client = self::request('POST', $scheduleUrl, $customerToken, [
            'scheduled_start_at' => self::futureDateTime(),
        ]);
        self::assertResponseStatusCodeSame(200);
        $scheduledBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($scheduledBody);
        self::assertSame('scheduled', $scheduledBody['status']);

        // Старт торгов (SCHEDULED → TRADE, T13) — системное действие по
        // расписанию (API старта нет), выполняем через доменный сервис.
        // EM и сервис — из ОДНОГО текущего контейнера (ядро перезагружается
        // перед каждым запросом, старый $em не управляет загруженным аукционом).
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $auctionService = $container->get(AuctionService::class);
        self::assertInstanceOf(AuctionService::class, $auctionService);
        $em->clear();
        $auction = $em->getRepository(Auction::class)->find($auctionId);
        self::assertInstanceOf(Auction::class, $auction);
        $auctionService->startTrading($auction);
        self::assertSame(AuctionStatusEnum::TRADE, $auction->getStatus());

        // ── 10. Ставка аукциона через API (FR-1.3.2, допущенный участник)
        $bidUrl = str_replace('{auctionId}', $auctionId, AuctionBidController::URL);
        $client = self::request('POST', $bidUrl, $supplierToken, ['price_minor' => self::START_MINOR - self::STEP_MINOR]);
        self::assertResponseStatusCodeSame(201);
        $auctionBid = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($auctionBid);
        self::assertSame(self::START_MINOR - self::STEP_MINOR, $auctionBid['price_minor']);
        self::assertSame('accepted', $auctionBid['status']);
        self::assertSame($supplier['company_id'], $auctionBid['bidder_id']);

        // ── 11. Авто-выбор победителя через API (FR-1.3.5, REDUCTION)
        $winnerUrl = str_replace('{auctionId}', $auctionId, AuctionWinnerController::URL);
        $client = self::request('POST', $winnerUrl, $customerToken, []);
        self::assertResponseStatusCodeSame(200);
        $winner = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($winner);
        self::assertSame('approve', $winner['status']);
        self::assertIsString($winner['winner_bid_id']);

        // ── 12. Договор по итогам тендера (source=tender, FR-1.4.3)
        $contractTypeId = (int) ContractTypeFactory::createOne()->getId();
        $client = self::request('POST', ContractCreateController::URL, $customerToken, [
            'contract_type_id' => (string) $contractTypeId,
            'source' => 'tender',
            'tender_id' => $tenderId,
            'scope' => 'multi_use',
            'customer_id' => $customer['company_id'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $contractBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($contractBody);
        self::assertSame('tender', $contractBody['source']);
        self::assertSame($supplier['company_id'], $contractBody['supplier_id']);
        self::assertSame('draft', $contractBody['status']);
        $contractId = $contractBody['id'];
        self::assertIsString($contractId);

        // ── 13. Подписание договора обеими сторонами (FR-1.4.3)
        $sendUrl = str_replace('{contractId}', $contractId, ContractSendForSignatureController::URL);
        $client = self::request('POST', $sendUrl, $customerToken);
        self::assertResponseStatusCodeSame(200);

        $signUrl = str_replace('{contractId}', $contractId, ContractSignController::URL);
        $client = self::request('POST', $signUrl, $customerToken, ['party' => 'customer', 'signature' => 'sig-customer']);
        self::assertResponseStatusCodeSame(200);
        $client = self::request('POST', $signUrl, $supplierToken, ['party' => 'supplier', 'signature' => 'sig-supplier']);
        self::assertResponseStatusCodeSame(200);
        $signed = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($signed);
        self::assertSame('signed', $signed['status']);

        // ── 14. Исполнение: start-work → mark-done → confirm-done (DONE, B2)
        $startWorkUrl = str_replace('{auctionId}', $auctionId, AuctionStartWorkController::URL);
        $client = self::request('POST', $startWorkUrl, $supplierToken);
        self::assertResponseStatusCodeSame(200);
        $inWork = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($inWork);
        self::assertSame('in_work', $inWork['status']);

        $markDoneUrl = str_replace('{auctionId}', $auctionId, AuctionMarkDoneController::URL);
        $client = self::request('POST', $markDoneUrl, $supplierToken);
        self::assertResponseStatusCodeSame(200);
        $doneByPerformer = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($doneByPerformer);
        self::assertSame('done_by_performer', $doneByPerformer['status']);

        $confirmDoneUrl = str_replace('{auctionId}', $auctionId, AuctionConfirmDoneController::URL);
        $client = self::request('POST', $confirmDoneUrl, $customerToken);
        self::assertResponseStatusCodeSame(200);
        $done = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($done);
        self::assertSame('done', $done['status']);

        // ── 15. Оценка исполнения (FR-1.1.10)
        $ratingUrl = str_replace('{tenderId}', $tenderId, TenderRateController::URL);
        $client = self::request('POST', $ratingUrl, $customerToken, ['execution_rating' => 9]);
        self::assertResponseStatusCodeSame(200);
        $rated = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($rated);
        self::assertSame(9, $rated['execution_rating']);

        // ── 16. Итоговое состояние в БД
        $em->clear();
        $freshAuction = $em->getRepository(Auction::class)->find($auctionId);
        self::assertInstanceOf(Auction::class, $freshAuction);
        self::assertSame(AuctionStatusEnum::DONE, $freshAuction->getStatus());

        $freshLot = $em->getRepository(Lot::class)->find($lotId);
        self::assertInstanceOf(Lot::class, $freshLot);
        self::assertSame(LotStatusEnum::CLOSED, $freshLot->getStatus());

        $freshContract = $em->getRepository(Contract::class)->find($contractId);
        self::assertInstanceOf(Contract::class, $freshContract);
        self::assertSame('signed', $freshContract->getStatus()->value);
        $boundTenders = $freshContract->getTenders();
        self::assertCount(1, $boundTenders);
        $firstTender = $boundTenders->first();
        self::assertNotFalse($firstTender);
        self::assertSame('done', $firstTender->getStatus()->value);

        $freshTender = $em->getRepository(Tender::class)->find($tenderId);
        self::assertInstanceOf(Tender::class, $freshTender);
        self::assertSame(TenderStatusEnum::CLOSED, $freshTender->aggregatedStatus());
        self::assertSame(9, $freshTender->getExecutionRating());

        $winningBid = $em->getRepository(Bid::class)->find($bidId);
        self::assertInstanceOf(Bid::class, $winningBid);
        self::assertSame('winning', $winningBid->getStatus()->value);

        // outbox-события исполнения (domain/events.md)
        $rows = $em->getConnection()
            ->executeQuery(
                'SELECT event_type FROM outbox_events WHERE aggregate_type = :t AND aggregate_id = :id',
                ['t' => 'auction', 'id' => $auctionId],
            )
            ->fetchFirstColumn();
        self::assertContains('execution.in_work', $rows);
        self::assertContains('execution.done_by_performer', $rows);
        self::assertContains('execution.done', $rows);
    }
}
