<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bid;

use App\Bid\BidOpeningService;
use App\Bid\Controller\BidListController;
use App\Bid\Controller\BidQualifyController;
use App\Bid\Controller\BidSubmitController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
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
 * Задача 3.3: read-путь после авто-вскрытия (FR-1.2.3, UC-06).
 *
 * - до вскрытия GET /tenders/{id}/bids — только метаданные (FR-1.2.2),
 *   содержимого (part1/price) нет ни у заказчика, ни у участника;
 * - после вскрытия заказчик видит полный состав (part1, part2_ref, price),
 *   участник — (в части) только part1 всех поданных заявок;
 * - своя заявка остаётся видна автору после рассмотрения (admitted/rejected),
 *   хотя чужие в этих статусах из выдачи участника уходят;
 * - событие tender.opened уходит в outbox.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class BidOpeningFlowTest extends WebTestCase
{
    use TenderLotTrait;

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
        return '18.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * Тендер заказчика в accepting_bids + токен admin-пользователя заказчика.
     *
     * @return array{tender: Tender, customerToken: string}
     */
    private static function customerTender(): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'customer-'.random_int(1000, 999999).'@test.ru',
        ]);

        $tender = TenderFactory::createOne(['nmckMinor' => 10000, 'customerId' => $customer->getId()]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 10000]);

        $container = static::getContainer();
        $workflow = $container->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $workflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $container->get(EntityManagerInterface::class)->flush();

        return ['tender' => $tender, 'customerToken' => self::loginAs((string) $customerUser->getEmail())];
    }

    /**
     * @return array{token: string, supplierId: string, email: string}
     */
    private static function supplier(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $email = 'supplier-'.random_int(1000, 999999).'@test.ru';
        $user = UserFactory::createOne(['companyId' => $company->getId(), 'role' => UserRoleEnum::ADMIN, 'email' => $email]);

        return ['token' => self::loginAs((string) $user->getEmail()), 'supplierId' => (string) $company->getId(), 'email' => (string) $email];
    }

    private static function submitUrl(string $tenderId): string
    {
        return str_replace('{tenderId}', $tenderId, BidSubmitController::URL);
    }

    private static function listUrl(string $tenderId): string
    {
        return str_replace('{tenderId}', $tenderId, BidListController::URL);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bidPayload(string $supplierId, string $lotId, string $marker, int $price): array
    {
        return [
            'supplier_id' => $supplierId,
            'lot_id' => $lotId,
            'part1' => ['consent' => true, 'characteristics' => ['marker' => $marker]],
            'part2_document_ids' => [],
            'price_minor' => $price,
            'price_basis' => 'net',
            'vat_rate' => 20,
        ];
    }

    public function testBidsVisibleAfterOpeningByRole(): void
    {
        self::client();
        $ctx = self::customerTender();
        $tender = $ctx['tender'];
        $customerToken = $ctx['customerToken'];

        $s1 = self::supplier();
        $s2 = self::supplier();
        $url = self::submitUrl((string) $tender->getId());

        self::request('POST', $url, $s1['token'], self::bidPayload($s1['supplierId'], self::firstLotId($tender), 'MARK-A', 900000));
        self::assertResponseStatusCodeSame(201);
        self::request('POST', $url, $s2['token'], self::bidPayload($s2['supplierId'], self::firstLotId($tender), 'MARK-B', 850000));
        self::assertResponseStatusCodeSame(201);

        // --- до вскрытия: только метаданные (FR-1.2.2) ---
        $listUrl = self::listUrl((string) $tender->getId());
        $client = self::request('GET', $listUrl, $customerToken);
        self::assertResponseStatusCodeSame(200);
        $before = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($before);
        $beforeItems = $before['items'];
        self::assertIsArray($beforeItems);
        self::assertCount(2, $beforeItems);
        foreach ($beforeItems as $item) {
            self::assertIsArray($item);
            self::assertTrue($item['payload_encrypted']);
            self::assertArrayNotHasKey('part1', $item);
            self::assertArrayNotHasKey('price_minor', $item);
        }

        // --- авто-вскрытие (FR-1.2.3): расшифровка + событие tender.opened ---
        $opening = static::getContainer()->get(BidOpeningService::class);
        self::assertInstanceOf(BidOpeningService::class, $opening);
        $opening->open((string) $tender->getId());
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $outbox = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'tender.opened' AND aggregate_id = :tender", [
                'tender' => (string) $tender->getId(),
            ])
            ->fetchOne();
        self::assertIsNumeric($outbox);
        self::assertSame(1, (int) $outbox);

        // --- заказчик видит полный состав после вскрытия (FR-1.2.3) ---
        $client = self::request('GET', $listUrl, $customerToken);
        self::assertResponseStatusCodeSame(200);
        $customerView = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($customerView);
        $customerItems = $customerView['items'];
        self::assertIsArray($customerItems);
        self::assertCount(2, $customerItems);
        $markers = [];
        foreach ($customerItems as $item) {
            self::assertIsArray($item);
            self::assertFalse($item['payload_encrypted']);
            self::assertSame('net', $item['price_basis']);
            self::assertArrayHasKey('price_minor', $item);
            $part1 = $item['part1'];
            self::assertIsArray($part1);
            $characteristics = $part1['characteristics'];
            self::assertIsArray($characteristics);
            self::assertIsString($characteristics['marker']);
            $markers[] = $characteristics['marker'];
        }
        sort($markers);
        self::assertSame(['MARK-A', 'MARK-B'], $markers);

        // --- участник видит (в части) только part1 всех поданных заявок ---
        $client = self::request('GET', $listUrl, $s1['token']);
        self::assertResponseStatusCodeSame(200);
        $supplierView = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($supplierView);
        $supplierItems = $supplierView['items'];
        self::assertIsArray($supplierItems);
        self::assertCount(2, $supplierItems);
        foreach ($supplierItems as $item) {
            self::assertIsArray($item);
            self::assertFalse($item['payload_encrypted']);
            self::assertArrayHasKey('part1', $item, 'participant sees part1 after opening');
            self::assertArrayNotHasKey('price_minor', $item, 'price hidden from participants');
            self::assertArrayNotHasKey('part2_ref', $item, 'part2 hidden from participants');
        }

        // --- после рассмотрения своя заявка остаётся у автора ---
        // Раньше выдача участника сводилась к submitted целиком, и допущенная
        // заявка исчезала из своего же списка: автор терял и решение
        // заказчика, и раздел документов части 2.
        $ownBefore = self::firstOwnBid($supplierItems, $s1['supplierId']);
        self::request(
            'POST',
            str_replace('{bidId}', $ownBefore, BidQualifyController::URL),
            $customerToken,
            ['decision' => 'admit', 'reason' => 'Соответствует требованиям'],
        );
        self::assertResponseStatusCodeSame(200);

        $client = self::request('GET', $listUrl, $s1['token']);
        self::assertResponseStatusCodeSame(200);
        $afterQualify = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($afterQualify);
        $afterItems = $afterQualify['items'];
        self::assertIsArray($afterItems);
        self::assertSame($ownBefore, self::firstOwnBid($afterItems, $s1['supplierId']));
        self::assertCount(2, $afterItems, 'own admitted bid + foreign submitted one');
    }

    /**
     * Идентификатор своей заявки в выдаче списка.
     *
     * @param array<mixed> $items
     */
    private static function firstOwnBid(array $items, string $supplierId): string
    {
        foreach ($items as $item) {
            self::assertIsArray($item);
            if ($item['supplier_id'] === $supplierId) {
                self::assertIsString($item['id']);

                return $item['id'];
            }
        }

        self::fail('own bid is missing from the list');
    }
}
