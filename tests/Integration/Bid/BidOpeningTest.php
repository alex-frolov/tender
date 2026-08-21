<?php

declare(strict_types=1);

namespace App\Tests\Integration\Bid;

use App\Bid\BidOpeningService;
use App\Bid\BidPayloadCipher;
use App\Bid\BidService;
use App\Bid\Entity\Bid;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\TenderLotTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 3.3: вскрытие по таймлайну + расшифровка (FR-1.2.3, UC-06).
 *
 * Интеграционный сценарий доказывает ключевые критерии:
 * - авто-вскрытие (BidOpeningService::open) расшифровывает encrypted_payload
 *   всех ПОДАННЫХ заявок → decrypted_payload заполняется (содержимое видно);
 * - сам шифротекст (encrypted_payload) не изменяется (аудит-след);
 * - фиксируется момент вскрытия (tenders.bids_opened_at);
 * - публикуется событие tender.opened (outbox, pending → RabbitMQ);
 * - идемпотентность: повторный вызов не дублирует событие/расшифровку;
 * - отозванные (withdrawn) заявки не вскрываются;
 * - тендер не в accepting_bids (published/cancelled) — no-op.
 */
final class BidOpeningTest extends KernelTestCase
{
    use TenderLotTrait;

    private EntityManagerInterface $em;
    private BidService $bidService;
    private BidOpeningService $opening;
    private WorkflowInterface $tenderWorkflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $service = $container->get(BidService::class);
        if (!$service instanceof BidService) {
            throw new \LogicException('BidService not resolvable');
        }
        $this->bidService = $service;

        $opening = $container->get(BidOpeningService::class);
        if (!$opening instanceof BidOpeningService) {
            throw new \LogicException('BidOpeningService not resolvable');
        }
        $this->opening = $opening;

        $workflow = $container->get('state_machine.tender');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Tender workflow not resolvable');
        }
        $this->tenderWorkflow = $workflow;
    }

    public function testOpeningDecryptsSubmittedBidsAndPublishesEvent(): void
    {
        $tender = $this->acceptingBidsTender();
        $supplier1 = $this->supplierUser();
        $supplier2 = $this->supplierUser();

        $bid1 = $this->bidService->submit(
            actor: $supplier1,
            tender: $tender,
            lotId: self::firstLotId($tender),
            part1: ['consent' => true, 'characteristics' => ['marker' => 'SECRET-A']],
            part2Ref: ['11111111-1111-4111-8111-111111111111'],
            priceMinor: 900000,
            priceBasis: PriceBasisEnum::NET,
            vatRate: 20,
        );
        $bid2 = $this->bidService->submit(
            actor: $supplier2,
            tender: $tender,
            lotId: self::firstLotId($tender),
            part1: ['consent' => true, 'characteristics' => ['marker' => 'SECRET-B']],
            part2Ref: ['22222222-2222-4222-8222-222222222222'],
            priceMinor: 850000,
            priceBasis: PriceBasisEnum::NET,
            vatRate: 20,
        );

        // до вскрытия содержимое недоступно (FR-1.2.2): decrypted_payload = null
        self::assertNull($bid1->getDecryptedPayload());
        self::assertNull($tender->getBidsOpenedAt());

        // --- авто-вскрытие (FR-1.2.3): расшифровка + событие ---
        $this->opening->open((string) $tender->getId());
        $this->em->clear();

        /** @var Tender $reloaded */
        $reloaded = $this->em->getRepository(Tender::class)->find($tender->getId());
        self::assertNotNull($reloaded->getBidsOpenedAt(), 'bids_opened_at must be set');

        /** @var Bid $reloadedBid1 */
        $reloadedBid1 = $this->em->getRepository(Bid::class)->find($bid1->getId());
        /** @var Bid $reloadedBid2 */
        $reloadedBid2 = $this->em->getRepository(Bid::class)->find($bid2->getId());

        // расшифрованное содержимое совпадает с поданным
        $payload1 = $reloadedBid1->getDecryptedPayload();
        self::assertIsArray($payload1);
        $part1 = $payload1['part1'];
        self::assertIsArray($part1);
        $characteristics = $part1['characteristics'];
        self::assertIsArray($characteristics);
        self::assertSame('SECRET-A', $characteristics['marker']);
        self::assertSame(900000, $payload1['price_minor']);

        $payload2 = $reloadedBid2->getDecryptedPayload();
        self::assertIsArray($payload2);
        $part1b = $payload2['part1'];
        self::assertIsArray($part1b);
        $characteristicsB = $part1b['characteristics'];
        self::assertIsArray($characteristicsB);
        self::assertSame('SECRET-B', $characteristicsB['marker']);

        // шифротекст не изменён (аудит-след): расшифровка round-trip работает
        $cipher = static::getContainer()->get(BidPayloadCipher::class);
        self::assertInstanceOf(BidPayloadCipher::class, $cipher);
        $decrypted = $cipher->decrypt($reloadedBid1->getEncryptedPayload());
        $part1RoundTrip = $decrypted['part1'];
        self::assertIsArray($part1RoundTrip);
        $roundTripChars = $part1RoundTrip['characteristics'];
        self::assertIsArray($roundTripChars);
        self::assertSame('SECRET-A', $roundTripChars['marker']);

        // --- событие tender.opened в outbox (pending → RabbitMQ) ---
        $event = $this->em->getConnection()
            ->executeQuery(
                'SELECT event_type, aggregate_id, tenant_id, payload FROM outbox_events WHERE event_type = :type',
                ['type' => 'tender.opened'],
            )
            ->fetchAssociative();
        self::assertIsArray($event);
        self::assertSame((string) $tender->getId(), $event['aggregate_id']);
        $payloadRaw = $event['payload'];
        self::assertIsString($payloadRaw);
        $payload = json_decode($payloadRaw, true);
        self::assertIsArray($payload);
        self::assertSame(2, $payload['bids_count']);
        self::assertSame((string) $tender->getId(), $payload['tender_id']);

        // --- аудит (FR-1.8): append-only запись вскрытия ---
        $audit = $this->em->getConnection()
            ->executeQuery(
                'SELECT action, entity_id, "after" FROM audit_log WHERE action = :action ORDER BY id DESC',
                ['action' => 'tender.opened'],
            )
            ->fetchAssociative();
        self::assertIsArray($audit);
        self::assertSame((string) $tender->getId(), $audit['entity_id']);
    }

    public function testOpeningIsIdempotent(): void
    {
        $tender = $this->acceptingBidsTender();
        $this->bidService->submit(
            actor: $this->supplierUser(),
            tender: $tender,
            lotId: self::firstLotId($tender),
            part1: ['consent' => true],
            part2Ref: [],
            priceMinor: 900000,
            priceBasis: null,
            vatRate: null,
        );

        $this->opening->open((string) $tender->getId());
        $this->opening->open((string) $tender->getId());
        $this->em->clear();

        $count = $this->em->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'tender.opened'")
            ->fetchOne();
        self::assertIsNumeric($count);
        self::assertSame(1, (int) $count, 'repeated opening must not duplicate the event');

        $reloaded = $this->em->getRepository(Tender::class)->find($tender->getId());
        self::assertInstanceOf(Tender::class, $reloaded);
        self::assertNotNull($reloaded->getBidsOpenedAt());
    }

    public function testWithdrawnBidIsNotOpened(): void
    {
        $tender = $this->acceptingBidsTender();
        $supplier = $this->supplierUser();

        $bid = $this->bidService->submit(
            actor: $supplier,
            tender: $tender,
            lotId: self::firstLotId($tender),
            part1: ['consent' => true, 'characteristics' => ['marker' => 'WITHDRAWN-SECRET']],
            part2Ref: [],
            priceMinor: 900000,
            priceBasis: null,
            vatRate: null,
        );
        $this->bidService->withdraw($supplier, (string) $bid->getId(), 'Сняли заявку');

        $this->opening->open((string) $tender->getId());
        $this->em->clear();

        $reloaded = $this->em->getRepository(Bid::class)->find($bid->getId());
        self::assertInstanceOf(Bid::class, $reloaded);
        self::assertSame('withdrawn', $reloaded->getStatus()->value);
        self::assertNull($reloaded->getDecryptedPayload(), 'withdrawn bid must not be decrypted');
    }

    public function testOpeningNonAcceptingTenderIsNoop(): void
    {
        $tender = TenderFactory::createOne(['nmckMinor' => 10000, 'customerId' => $this->customerCompany()->getId()]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 10000]);
        // статус — published (приём не начат): вскрытие не выполняется

        $this->opening->open((string) $tender->getId());
        $this->em->clear();

        $reloaded = $this->em->getRepository(Tender::class)->find($tender->getId());
        self::assertInstanceOf(Tender::class, $reloaded);
        self::assertNull($reloaded->getBidsOpenedAt());

        $count = $this->em->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'tender.opened'")
            ->fetchOne();
        self::assertIsNumeric($count);
        self::assertSame(0, (int) $count, 'no event for non-accepting tender');
    }

    public function testOpeningCancelledTenderIsNoop(): void
    {
        $tender = $this->acceptingBidsTender();
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::CANCEL->value);
        $this->em->flush();

        $this->opening->open((string) $tender->getId());
        $this->em->clear();

        $reloaded = $this->em->getRepository(Tender::class)->find($tender->getId());
        self::assertInstanceOf(Tender::class, $reloaded);
        self::assertNull($reloaded->getBidsOpenedAt());
    }

    private function supplierUser(): User
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();

        return UserFactory::createOne(['companyId' => $company->getId(), 'role' => UserRoleEnum::ADMIN]);
    }

    private function customerCompany(): Company
    {
        return CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
    }

    /**
     * Тендер в статусе accepting_bids (через workflow, FR-1.1.4).
     */
    private function acceptingBidsTender(): Tender
    {
        $tender = TenderFactory::createOne(['nmckMinor' => 10000, 'customerId' => $this->customerCompany()->getId()]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 10000]);

        $this->tenderWorkflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $this->em->flush();

        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $tender->getStatus());

        return $tender;
    }
}
