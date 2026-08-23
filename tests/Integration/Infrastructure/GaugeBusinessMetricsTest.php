<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure;

use App\Infrastructure\Metrics\GaugeMetricsUpdater;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Бизнес-гейджи GaugeMetricsUpdater: таймлайн, статусы тендеров и договоров,
 * просрочка вскрытия, очередь верификации. Пересчёт выполняется без ошибок,
 * все серии зарегистрированы в хранилище.
 *
 * Значения НЕ проверяем на точность: Redis-хранилище метрик общее у всех
 * параллельных воркеров, параллельный скрейп /metrics другого процесса может
 * перезаписать gauge между нашим обновлением и ассертом. Точность рендера
 * покрывают юнит-тесты коллекторов; здесь проверяем проводку (DI, запросы,
 * отсутствие исключений) и факт регистрации серий.
 */
final class GaugeBusinessMetricsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private WorkflowInterface $tenderWorkflow;

    private \Redis $redis;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        $workflow = $container->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $this->tenderWorkflow = $workflow;

        $redis = $container->get(\Redis::class);
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
    }

    protected function tearDown(): void
    {
        // Ключи общей тестовой БД Redis (db 1): транспорт в тестах in-memory,
        // но ключи метрик/очереди чистим точечно, без FLUSHALL.
        $this->redis->del('messages__queue', 'tender_metrics:gauges:fresh');

        parent::tearDown();
    }

    public function testRefreshComputesBusinessGauges(): void
    {
        // Тендер в accepting_bids с прошедшим bids_end: источник данных для
        // bid_opening_overdue_seconds и tenders_by_status.
        $tender = TenderFactory::createOne(['nmckMinor' => 1000]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000]);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $tender->setTimeline([
            'bids_start' => (new \DateTimeImmutable('-20 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'bids_end' => (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ]);
        $this->em->flush();

        // Компания по умолчанию создаётся в pending — очередь верификации не пуста.
        CompanyFactory::createOne();

        // Отложенная задача таймлайна с просроченным runAt (score = момент запуска).
        $this->redis->zAdd('messages__queue', [], microtime(true) - 120, 'test-delayed-job');

        $this->redis->del('tender_metrics:gauges:fresh');

        /** @var GaugeMetricsUpdater $updater */
        $updater = self::getContainer()->get(GaugeMetricsUpdater::class);
        $updater->refreshIfDue();

        // Пересчёт реально выполнился (кэш помечен свежим) — значит ни один
        // из новых запросов не упал мимо алерта MetricsGaugeRefreshFailed.
        self::assertNotFalse($this->redis->get('tender_metrics:gauges:fresh'));

        $body = $this->metricsBody();
        foreach ([
            'tenders_by_status',
            'contracts_by_status',
            'bid_opening_overdue_seconds',
            'companies_pending_verification',
            'timeline_queue_depth{queue="ready"}',
            'timeline_queue_depth{queue="delayed"}',
            'timeline_overdue_seconds',
        ] as $series) {
            self::assertStringContainsString($series, $body, "Серия {$series} должна быть зарегистрирована");
        }
    }

    /**
     * Отсутствие ключей транспорта читается как пустая очередь (0), а не ошибка.
     */
    public function testEmptyTimelineQueueIsReportedAsZeroDepth(): void
    {
        $this->redis->del('messages__queue');
        $this->redis->del('tender_metrics:gauges:fresh');

        /** @var GaugeMetricsUpdater $updater */
        $updater = self::getContainer()->get(GaugeMetricsUpdater::class);
        $updater->refreshIfDue();

        $body = $this->metricsBody();
        self::assertStringContainsString('timeline_queue_depth{queue="delayed"} 0', $body);
    }

    private function metricsBody(): string
    {
        $registry = self::getContainer()->get(CollectorRegistry::class);
        self::assertInstanceOf(CollectorRegistry::class, $registry);

        return (new RenderTextFormat())->render($registry->getMetricFamilySamples());
    }
}
