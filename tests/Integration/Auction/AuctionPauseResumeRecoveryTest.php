<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\AuctionBidService;
use App\Auction\AuctionService;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Exception\BidRejectedException;
use App\Auction\State\AuctionStateService;
use App\Shared\Entity\AuditLog;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Exception\StateTransitionException;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 4.8: PAUSED/восстановление после сбоя (UC-15, FR-1.3.6).
 *
 * - pause() (T20): TRADE → PAUSED; таймер заморожен (paused_remaining_sec в БД),
 *   ставки не принимаются; событие auction.paused (remaining_sec), аудит;
 * - resume() (T21): PAUSED → TRADE; таймер продолжен с остатка
 *   (planned_end_at = resume_time + paused_remaining_sec); событие auction.resumed;
 * - autoPauseStale(): авто-пауза TRADE-аукционов, чей heartbeat в Redis
 *   пропал/простоил дольше порога (UC-15);
 * - восстановление после сбоя Redis: rebuildAll() из PostgreSQL (источник истины)
 *   → авто-PAUSED (простой > порога) → resume → ставки целы (FR-1.3.6).
 */
final class AuctionPauseResumeRecoveryTest extends KernelTestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;
    private const HEARTBEAT_TIMEOUT = 300;

    private EntityManagerInterface $em;
    private AuctionBidService $bidService;
    private AuctionService $auctionService;
    private AuctionStateService $state;
    private WorkflowInterface $auctionWorkflow;
    private \Redis $redis;

    /** @var list<string> Redis-ключи для очистки в tearDown */
    private array $trackedKeys = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $bidService = $container->get(AuctionBidService::class);
        if (!$bidService instanceof AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $this->bidService = $bidService;

        $auctionService = $container->get(AuctionService::class);
        if (!$auctionService instanceof AuctionService) {
            throw new \LogicException('AuctionService not resolvable');
        }
        $this->auctionService = $auctionService;

        $state = $container->get(AuctionStateService::class);
        if (!$state instanceof AuctionStateService) {
            throw new \LogicException('AuctionStateService not resolvable');
        }
        $this->state = $state;

        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $this->auctionWorkflow = $workflow;

        $redis = $container->get(\Redis::class);
        if (!$redis instanceof \Redis) {
            throw new \LogicException('Redis not resolvable');
        }
        $this->redis = $redis;
    }

    protected function tearDown(): void
    {
        foreach ($this->trackedKeys as $key) {
            $this->redis->del($key);
        }
        $this->trackedKeys = [];
        parent::tearDown();
    }

    public function testPauseFreezesTimerAndRejectsBids(): void
    {
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(startAt: $start);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);
        // Ставка ровно в момент старта (вне окна антиснайпинга):
        // planned_end остаётся 10:10.
        $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierId,
            self::START_MINOR - self::STEP_MINOR,
            now: self::at('2026-01-01T10:00:00Z'),
        );

        $pausedAt = self::at('2026-01-01T10:07:00Z');
        $this->auctionService->pause($auction, 'incident', now: $pausedAt);

        // TRADE → PAUSED; таймер заморожен: остаток = planned_end (10:10) − pause (10:07) = 180 сек.
        self::assertSame(AuctionStatusEnum::PAUSED, $auction->getStatus());
        self::assertSame(180, $auction->getPausedRemainingSec());
        self::assertSame('2026-01-01T10:07:00Z', $auction->getPausedAt()?->format('Y-m-d\TH:i:s\Z'));

        // Событие auction.paused + аудит.
        $event = $this->findOutbox('auction.paused', $auction->getId());
        self::assertNotNull($event);
        self::assertSame('incident', $event->getPayload()['reason']);
        self::assertSame(180, $event->getPayload()['remaining_sec']);
        self::assertNotNull($this->findAudit('auction.paused', $auction->getId()));

        // Ставки в PAUSED не принимаются (FR-1.3.2: только TRADE).
        $this->expectException(BidRejectedException::class);
        $this->bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - 2 * self::STEP_MINOR);
    }

    public function testResumeContinuesTimerFromRemaining(): void
    {
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(startAt: $start);

        // Пауза на 10 минут: остаток = 600 − 600 = 0 (пауза ровно на весь шаг).
        $this->auctionService->pause($auction, 'incident', now: self::at('2026-01-01T10:10:00Z'));
        self::assertSame(0, $auction->getPausedRemainingSec());

        // Возобновление через 1 час: planned_end_at = resume_time + остаток.
        $resumeAt = self::at('2026-01-01T11:10:00Z');
        $this->auctionService->resume($auction, now: $resumeAt);

        self::assertSame(AuctionStatusEnum::TRADE, $auction->getStatus());
        self::assertSame('2026-01-01T11:10:00Z', $auction->getPlannedEndAt()?->format('Y-m-d\TH:i:s\Z'));
        self::assertNull($auction->getPausedRemainingSec());
        self::assertNull($auction->getPausedAt());

        // Событие auction.resumed + аудит.
        $event = $this->findOutbox('auction.resumed', $auction->getId());
        self::assertNotNull($event);
        self::assertSame('2026-01-01T11:10:00Z', $event->getPayload()['new_end_at']);
        self::assertNotNull($this->findAudit('auction.resumed', $auction->getId()));
    }

    public function testResumeKeepsRemainingWhenPausedBeforeEnd(): void
    {
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(startAt: $start);

        // Пауза на 4-й минуте: остаток = 10:10 − 10:04 = 360 сек.
        $this->auctionService->pause($auction, 'incident', now: self::at('2026-01-01T10:04:00Z'));
        self::assertSame(360, $auction->getPausedRemainingSec());

        // Возобновление: planned_end_at = resume (10:30) + 360 сек = 10:36.
        $this->auctionService->resume($auction, now: self::at('2026-01-01T10:30:00Z'));
        self::assertSame('2026-01-01T10:36:00Z', $auction->getPlannedEndAt()?->format('Y-m-d\TH:i:s\Z'));
    }

    public function testPauseRequiresTradeAndResumeRequiresPaused(): void
    {
        $auction = $this->tradingAuction();

        // Повторная пауза из PAUSED недопустима.
        $this->auctionService->pause($auction, 'incident');
        try {
            $this->auctionService->pause($auction, 'again');
            self::fail('Pause from PAUSED must throw');
        } catch (StateTransitionException $e) {
            self::assertSame(409, $e->getHttpStatus());
        }

        // Resume из PAUSED → TRADE → повторный resume недопустим.
        $this->auctionService->resume($auction);
        try {
            $this->auctionService->resume($auction);
            self::fail('Resume from TRADE must throw');
        } catch (StateTransitionException $e) {
            self::assertSame(409, $e->getHttpStatus());
        }
    }

    public function testResumeWithoutPausedRemainingThrows(): void
    {
        $auction = $this->tradingAuction();
        // Аукцион в TRADE — resume недопустим (нужен PAUSED + сохранённый остаток).
        $this->expectException(StateTransitionException::class);
        $this->auctionService->resume($auction);
    }

    public function testAutoPauseStalePausesSilentAuctions(): void
    {
        $now = self::at('2026-01-01T12:00:00Z');
        $auctionA = $this->tradingAuction(startAt: self::at('2026-01-01T10:00:00Z'));
        $auctionB = $this->tradingAuction(startAt: self::at('2026-01-01T10:00:00Z'));

        // auctionB имеет свежий heartbeat — не должен попасть в авто-паузу.
        $this->state->heartbeat($auctionB->getId(), now: $now);

        // У auctionA heartbeat отсутствует (Redis «сбой») → простой = null → пауза.
        $paused = $this->auctionService->autoPauseStale(self::HEARTBEAT_TIMEOUT, now: $now);

        self::assertSame(1, $paused);
        self::assertSame(AuctionStatusEnum::PAUSED, $auctionA->getStatus());
        self::assertSame(AuctionStatusEnum::TRADE, $auctionB->getStatus());

        // Причина паузы — heartbeat_timeout (событие + аудит).
        $event = $this->findOutbox('auction.paused', $auctionA->getId());
        self::assertNotNull($event);
        self::assertSame('heartbeat_timeout', $event->getPayload()['reason']);
    }

    public function testAutoPauseStaleSkipsFreshHeartbeat(): void
    {
        $now = self::at('2026-01-01T12:00:00Z');
        $auction = $this->tradingAuction(startAt: self::at('2026-01-01T11:59:30Z'));
        $this->state->heartbeat($auction->getId(), now: self::at('2026-01-01T11:59:45Z'));

        $paused = $this->auctionService->autoPauseStale(self::HEARTBEAT_TIMEOUT, now: $now);

        self::assertSame(0, $paused);
        self::assertSame(AuctionStatusEnum::TRADE, $auction->getStatus());
    }

    public function testRecoveryAfterRedisKillKeepsBids(): void
    {
        // Сценарий UC-15: «убийство Redis → рестарт → TRADE → авто-PAUSED
        // (простой > порога) → resume → ставки целы».
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(startAt: $start);
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        // Две принятые ставки до «сбоя» — внутри окна торгов (старт заморожен
        // на фиксированной дате, поэтому момент ставки задаём явно).
        $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierA,
            self::START_MINOR - self::STEP_MINOR,
            now: $start->modify('+1 minute'),
        );
        $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierB,
            self::START_MINOR - 2 * self::STEP_MINOR,
            now: $start->modify('+2 minutes'),
        );

        // «Сбой»: heartbeat и снапшоты потеряны (Redis упал).
        $this->trackKeys($auction);
        $this->redis->del(
            'auction:state:'.$auction->getId(),
            'auction:heartbeat:'.$auction->getId(),
        );

        // Восстановление: источник истины (PostgreSQL) → снапшоты.
        $rebuilt = $this->state->rebuildAll();
        self::assertGreaterThanOrEqual(1, $rebuilt);

        // Авто-пауза TRADE-аукциона без heartbeat (простой > порога).
        $paused = $this->auctionService->autoPauseStale(self::HEARTBEAT_TIMEOUT, now: self::at('2026-01-01T10:20:00Z'));
        self::assertSame(1, $paused);
        self::assertSame(AuctionStatusEnum::PAUSED, $auction->getStatus());

        // Возобновление: таймер продолжен с остатка.
        $this->auctionService->resume($auction, now: self::at('2026-01-01T10:30:00Z'));
        self::assertSame(AuctionStatusEnum::TRADE, $auction->getStatus());

        // Ни одна принятая ставка не потеряна (FR-1.3.6: at-least-once, идемпотентность).
        self::assertSame(2, $this->countBids($auction));
        self::assertSame(self::START_MINOR - 2 * self::STEP_MINOR, $auction->getCurrentPriceMinor());
    }

    private function tradingAuction(?\DateTimeImmutable $startAt = null): Auction
    {
        $startAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction, now: $startAt);
        $this->trackKeys($auction);

        return $auction;
    }

    private function admittedBid(Auction $auction, Uuid $supplierId): void
    {
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
    }

    /** Регистрация Redis-ключей аукциона для очистки в tearDown. */
    private function trackKeys(Auction $auction): void
    {
        $this->trackedKeys[] = 'auction:state:'.$auction->getId();
        $this->trackedKeys[] = 'auction:heartbeat:'.$auction->getId();
    }

    private function findAudit(string $action, Uuid $entityId): ?AuditLog
    {
        /** @var AuditLog|null $log */
        $log = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => $action, 'entityId' => (string) $entityId]);

        return $log;
    }

    private function findOutbox(string $eventType, Uuid $aggregateId): ?OutboxEvent
    {
        /** @var OutboxEvent|null $event */
        $event = $this->em->getRepository(OutboxEvent::class)->findOneBy([
            'eventType' => $eventType,
            'aggregateId' => (string) $aggregateId,
        ]);

        return $event;
    }

    private function countBids(Auction $auction): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(\App\Auction\Entity\AuctionBid::class, 'b')
            ->where('b.auction = :auction')
            ->setParameter('auction', $auction)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }
}
