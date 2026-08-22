<?php

declare(strict_types=1);

namespace App\Tender\Timeline;

use App\Bid\BidOpeningService;
use App\Shared\Audit\AuditService;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
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
 */
#[AsMessageHandler]
final readonly class TimelineMessageHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private LoggerInterface $logger,
        private BidOpeningService $bidOpening,
        #[Autowire(service: 'state_machine.tender')]
        private WorkflowInterface $tenderWorkflow,
    ) {
    }

    public function __invoke(TimelineMessage $message): void
    {
        if ('tender' !== $message->aggregateType) {
            return;
        }

        $tender = $this->em->getRepository(Tender::class)->find($message->aggregateId);
        if (null === $tender) {
            $this->logger->warning('Timeline: tender not found', ['tender_id' => $message->aggregateId]);

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

            return;
        }

        $this->tenderWorkflow->apply($tender, $transition);
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
    }

    /**
     * Авто-вскрытие заявок по таймлайну (FR-1.2.3, UC-06): расшифровка
     * содержимого поданных заявок + событие tender.opened. Идемпотентно:
     * BidOpeningService сам пропускает уже вскрытые тендеры и не-принимающие
     * статусы, поэтому повторная доставка сообщения ничего не дублирует.
     */
    private function openBids(Tender $tender): void
    {
        $this->bidOpening->open((string) $tender->getId());
    }
}
