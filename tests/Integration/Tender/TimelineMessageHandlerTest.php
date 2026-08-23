<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tender;

use App\Bid\BidService;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
use App\Tender\Timeline\TenderTimelineAction;
use App\Tender\Timeline\TimelineMessage;
use App\Tender\Timeline\TimelineMessageHandler;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\TenderLotTrait;
use Doctrine\ORM\EntityManagerInterface;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * FR-1.1.4: авто-переходы по расписанию (таймлайн).
 *
 * TimelineMessageHandler переводит опубликованный тендер published →
 * accepting_bids через workflow, когда наступает срок bids_start. Повторная
 * доставка идемпотентна (workflow не допускает переход из accepting_bids).
 */
final class TimelineMessageHandlerTest extends KernelTestCase
{
    use TenderLotTrait;

    private EntityManagerInterface $em;
    private TimelineMessageHandler $handler;
    private WorkflowInterface $tenderWorkflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $handler = $container->get(TimelineMessageHandler::class);
        if (!$handler instanceof TimelineMessageHandler) {
            throw new \LogicException('TimelineMessageHandler not resolvable');
        }
        $this->handler = $handler;

        $workflow = $container->get('state_machine.tender');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Tender workflow not resolvable');
        }
        $this->tenderWorkflow = $workflow;
    }

    public function testStartBidAcceptanceTransitionsPublishedToAcceptingBids(): void
    {
        $tender = $this->publishedTender();

        $this->handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: TenderTimelineAction::START_BID_ACCEPTANCE->value,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        $this->em->clear();
        $reloaded = $this->em->getRepository(Tender::class)->find((string) $tender->getId());
        self::assertNotNull($reloaded);
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $reloaded->getStatus());
    }

    public function testRepeatedDeliveryIsIdempotent(): void
    {
        $tender = $this->publishedTender();

        $this->handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: TenderTimelineAction::START_BID_ACCEPTANCE->value,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
        $this->handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: TenderTimelineAction::START_BID_ACCEPTANCE->value,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        $this->em->clear();
        $reloaded = $this->em->getRepository(Tender::class)->find((string) $tender->getId());
        self::assertNotNull($reloaded);
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $reloaded->getStatus());
    }

    public function testUnknownActionLeavesStatusUnchanged(): void
    {
        $tender = $this->publishedTender();

        $this->handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: 'tender.unknown',
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        $this->em->clear();
        $reloaded = $this->em->getRepository(Tender::class)->find((string) $tender->getId());
        self::assertNotNull($reloaded);
        self::assertSame(TenderStatusEnum::PUBLISHED, $reloaded->getStatus());
    }

    public function testOpenBidsDecryptsSubmittedBids(): void
    {
        $tender = $this->acceptingBidsTender();

        $bidService = $this->getContainer()->get(BidService::class);
        self::assertInstanceOf(BidService::class, $bidService);
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne(['companyId' => $company->getId(), 'role' => UserRoleEnum::ADMIN]);

        $bid = $bidService->submit(
            actor: $user,
            tender: $tender,
            lotId: self::firstLotId($tender),
            part1: ['consent' => true, 'characteristics' => ['marker' => 'AUTO-OPENED']],
            part2Ref: [],
            priceMinor: 900000,
            priceBasis: null,
            vatRate: null,
        );

        // авто-вскрытие по таймлайну (FR-1.2.3): action tender.open_bids
        $this->handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: TenderTimelineAction::OPEN_BIDS->value,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        $this->em->clear();
        $reloaded = $this->em->getRepository(\App\Bid\Entity\Bid::class)->find($bid->getId());
        self::assertNotNull($reloaded);
        $payload = $reloaded->getDecryptedPayload();
        self::assertIsArray($payload);
        $part1 = $payload['part1'];
        self::assertIsArray($part1);
        $characteristics = $part1['characteristics'];
        self::assertIsArray($characteristics);
        self::assertSame('AUTO-OPENED', $characteristics['marker']);

        $reloadedTender = $this->em->getRepository(Tender::class)->find($tender->getId());
        self::assertNotNull($reloadedTender);
        self::assertNotNull($reloadedTender->getBidsOpenedAt());
    }

    public function testRepeatedOpenBidsIsIdempotent(): void
    {
        $tender = $this->acceptingBidsTender();

        $bidService = $this->getContainer()->get(BidService::class);
        self::assertInstanceOf(BidService::class, $bidService);
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne(['companyId' => $company->getId(), 'role' => UserRoleEnum::ADMIN]);
        $bidService->submit(
            actor: $user,
            tender: $tender,
            lotId: self::firstLotId($tender),
            part1: ['consent' => true],
            part2Ref: [],
            priceMinor: 900000,
            priceBasis: null,
            vatRate: null,
        );

        $message = new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: TenderTimelineAction::OPEN_BIDS->value,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $this->handler->__invoke($message);
        $this->handler->__invoke($message);

        $this->em->clear();
        $count = $this->em->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'tender.opened'")
            ->fetchOne();
        self::assertIsNumeric($count);
        self::assertSame(1, (int) $count);
    }

    // --- Наблюдаемость очереди таймлайна: timeline_jobs_total ---

    public function testStartBidAcceptanceEmitsAppliedMetric(): void
    {
        $tender = $this->publishedTender();

        $this->handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: TenderTimelineAction::START_BID_ACCEPTANCE->value,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        $this->assertMetricAtLeast('timeline_jobs_total{action="start_bid_acceptance",outcome="applied"}', 1.0);
    }

    public function testUnknownActionEmitsSkippedMetric(): void
    {
        $tender = $this->publishedTender();

        $this->handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: 'tender.unknown',
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        $this->assertMetricAtLeast('timeline_jobs_total{action="tender.unknown",outcome="skipped"}', 1.0);
    }

    public function testRepeatedOpenBidsEmitsAppliedThenSkipped(): void
    {
        $tender = $this->acceptingBidsTender();

        $message = new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: TenderTimelineAction::OPEN_BIDS->value,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $this->handler->__invoke($message);
        $this->handler->__invoke($message);

        $body = $this->metricsBody();
        // Redis-хранилище метрик общее между тестами — значения копятся,
        // поэтому проверяем наличие серий, а не точные числа.
        self::assertMatchesRegularExpression(
            '/timeline_jobs_total\{action="'.TenderTimelineAction::OPEN_BIDS->value.'",outcome="applied"\} [\d.]+/',
            $body,
        );
        self::assertMatchesRegularExpression(
            '/timeline_jobs_total\{action="'.TenderTimelineAction::OPEN_BIDS->value.'",outcome="skipped"\} [\d.]+/',
            $body,
        );
    }

    private function metricsBody(): string
    {
        $registry = self::getContainer()->get(CollectorRegistry::class);
        self::assertInstanceOf(CollectorRegistry::class, $registry);

        return (new RenderTextFormat())->render($registry->getMetricFamilySamples());
    }

    /**
     * Серия существует и её значение >= ожидаемого (метрики в Redis копятся
     * между тестами — точные значения недетерминированы).
     */
    private function assertMetricAtLeast(string $series, float $min): void
    {
        $value = $this->metricValue($series);
        self::assertNotNull($value, "Серия {$series} не найдена в рендере метрик");
        self::assertGreaterThanOrEqual($min, $value);
    }

    private function metricValue(string $series): ?float
    {
        if (1 !== preg_match('/'.preg_quote($series, '/').' ([\d.]+)/', $this->metricsBody(), $m)) {
            return null;
        }

        return (float) $m[1];
    }

    private function acceptingBidsTender(): Tender
    {
        $tender = TenderFactory::createOne(['nmckMinor' => 10000]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 10000]);

        $this->tenderWorkflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $this->em->flush();

        return $tender;
    }

    private function publishedTender(): Tender
    {
        $tender = TenderFactory::createOne([
            'nmckMinor' => 1000,
        ]);
        LotFactory::createOne([
            'tender' => $tender,
            'priceNetMinor' => 1000,
        ]);

        $this->tenderWorkflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $this->em->flush();

        return $tender;
    }
}
