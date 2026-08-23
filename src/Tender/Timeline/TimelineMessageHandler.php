<?php

declare(strict_types=1);

namespace App\Tender\Timeline;

use App\Bid\BidOpeningService;
use App\Shared\Audit\AuditService;
use App\Tender\Entity\Enum\LotStatusTransition;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
use App\Tender\Service\LotPhaseService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Обработчик отложенных задач таймлайна (FR-1.1.4, FR-1.2.3).
 *
 * TimelineMessage доставляется через Redis-транспорт с DelayStamp на момент
 * срабатывания:
 * - tender.start_bid_acceptance (bids_start) — published → accepting_bids;
 *   у тендера без заявок на участие (bids_required=false) фазы accepting_bids
 *   нет, и та же задача открывает торги: published → bidding;
 * - tender.open_bids (bids_end) — авто-вскрытие заявок (BidOpeningService).
 * Переходы выполняются только если workflow/статус их допускает
 * (идемпотентность: повторная доставка не дублирует обработку).
 *
 * Обработчик работает в контексте worker (live-транспорт), поэтому повреждённая
 * транзакция не волнует: каждый обработанный тендер выполняет собственный flush.
 *
 * Наблюдаемость: каждая обработка задачи пишется в
 * timeline_jobs_total{action,outcome} — applied | skipped | failed. Упавший
 * worker по этой очереди иначе не виден никем: RabbitMQ-экспортер Redis-транспорт
 * не видит, HTTP и health при этом зелёные.
 */
#[AsMessageHandler]
final readonly class TimelineMessageHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private LoggerInterface $logger,
        private BidOpeningService $bidOpening,
        private LotPhaseService $lotPhases,
        private TimelineJobRecorder $jobMetrics,
        #[Autowire(service: 'state_machine.tender')]
        private WorkflowInterface $tenderWorkflow,
    ) {
    }

    public function __invoke(TimelineMessage $message): void
    {
        if ('tender' !== $message->aggregateType) {
            return;
        }

        try {
            $tender = $this->em->getRepository(Tender::class)->find($message->aggregateId);
            if (null === $tender) {
                $this->logger->warning('Timeline: tender not found', ['tender_id' => $message->aggregateId]);
                $this->jobMetrics->record($message->action, TimelineJobRecorder::OUTCOME_SKIPPED);

                return;
            }

            if (TenderTimelineAction::START_BID_ACCEPTANCE->value === $message->action) {
                $this->startProcedure($tender);

                return;
            }

            if (TenderTimelineAction::OPEN_BIDS->value === $message->action) {
                $this->openBids($tender);

                return;
            }

            $this->logger->warning('Timeline: unknown action', ['action' => $message->action]);
            $this->jobMetrics->record($message->action, TimelineJobRecorder::OUTCOME_SKIPPED);
        } catch (\Throwable $e) {
            // Счётчик отказа до ретрая messenger'ом: повторная доставка
            // инкрементит failed ещё раз — это честно отражает число неудачных
            // попыток, а не задач.
            $this->jobMetrics->record($message->action, TimelineJobRecorder::OUTCOME_FAILED);

            throw $e;
        }
    }

    /**
     * Момент bids_start: тендер выходит из ожидания старта. С заявками на
     * участие — в приём заявок (accepting_bids), без них — сразу в торги
     * (bidding): подавать и допускать нечего, участвовать может любой, кому
     * тендер доступен (access_type/договор).
     */
    private function startProcedure(Tender $tender): void
    {
        $bidsRequired = $tender->isBidsRequired();
        $transition = $bidsRequired
            ? TenderStatusTransition::START_BID_ACCEPTANCE->value
            : TenderStatusTransition::START_TRADE_WITHOUT_BIDS->value;

        if (!$this->tenderWorkflow->can($tender, $transition)) {
            $this->logger->warning('Timeline: procedure start transition not allowed', [
                'tender_id' => (string) $tender->getId(),
                'transition' => $transition,
                'status' => $tender->getStatus()->value,
            ]);
            $this->jobMetrics->record($transition, TimelineJobRecorder::OUTCOME_SKIPPED);

            return;
        }

        $this->tenderWorkflow->apply($tender, $transition);
        // Лоты идут за тендером: с заявками на участие — в приём заявок,
        // без них — сразу в торги (фазы accepting_bids у таких лотов нет).
        $this->lotPhases->applyToTender(
            $tender,
            $bidsRequired ? LotStatusTransition::START_BID_ACCEPTANCE : LotStatusTransition::START_TRADE,
        );
        $this->em->flush();

        $this->audit->record(
            action: $bidsRequired ? 'tender.bids_opened' : 'tender.trade_opened',
            entityType: 'tender',
            entityId: (string) $tender->getId(),
            tenantId: (string) $tender->getTenantId(),
            after: [
                'status' => ($bidsRequired ? TenderStatusEnum::ACCEPTING_BIDS : TenderStatusEnum::BIDDING)->value,
                'timeline' => $tender->getTimeline(),
            ],
        );

        $this->jobMetrics->record($transition, TimelineJobRecorder::OUTCOME_APPLIED);
    }

    /**
     * Авто-вскрытие заявок по таймлайну (FR-1.2.3, UC-06): расшифровка
     * содержимого поданных заявок + событие tender.opened. Идемпотентно:
     * BidOpeningService сам пропускает уже вскрытые тендеры и не-принимающие
     * статусы, поэтому повторная доставка сообщения ничего не дублирует.
     *
     * Исход различается ПОСЛЕ вызова (BidOpeningService возвращает void):
     * bids_opened_at проставлен — вскрытие выполнено, нет — пропуск
     * (повторная доставка или статус уже не accepting_bids).
     */
    private function openBids(Tender $tender): void
    {
        $openedAtBefore = $tender->getBidsOpenedAt();

        $this->bidOpening->open((string) $tender->getId());

        // Исход различается ПОСЛЕ вызова: bids_opened_at проставлен — вскрытие
        // выполнено (или уже было), нет — пропуск (статус не accepting_bids).
        // Счётчик bid_opening_total{outcome} пишет сам BidOpeningService.
        $outcome = null !== $openedAtBefore || null === $tender->getBidsOpenedAt()
            ? TimelineJobRecorder::OUTCOME_SKIPPED
            : TimelineJobRecorder::OUTCOME_APPLIED;
        $this->jobMetrics->record(TenderTimelineAction::OPEN_BIDS->value, $outcome);
    }
}
