<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Tender\Entity\Tender;
use App\Tender\Timeline\TenderTimelineAction;
use App\Tender\Timeline\TimelineMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Планировщик отложенных задач таймлайна тендера (FR-1.1.4).
 *
 * Доменная ответственность: «запланировать авто-переход/авто-вскрытие
 * на момент из timeline». Задачи доставляются через Redis-транспорт
 * (TTL-поддержка) с задержкой DelayStamp от момента вызова.
 */
final readonly class TenderTimelineScheduler
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /**
     * Запланировать авто-переход published → accepting_bids на момент bids_start
     * (FR-1.1.4). Если bids_start не рассчитан правилами — переход не планируется.
     *
     * @param array<string, string> $timeline
     */
    public function scheduleStartBidAcceptance(Tender $tender, array $timeline): void
    {
        $bidsStart = $timeline['bids_start'] ?? null;
        if (null === $bidsStart) {
            return;
        }

        $runAt = new \DateTimeImmutable($bidsStart, new \DateTimeZone('UTC'));
        $delayMs = max(0, ($runAt->getTimestamp() - time()) * 1000);

        $this->bus->dispatch(
            new TimelineMessage(
                aggregateType: 'tender',
                aggregateId: (string) $tender->getId(),
                action: TenderTimelineAction::START_BID_ACCEPTANCE->value,
                runAt: $runAt,
                context: ['status' => $tender->getStatus()->value],
            ),
            [new DelayStamp($delayMs)],
        );
    }

    /**
     * Запланировать авто-вскрытие заявок на момент bids_end (FR-1.2.3, UC-06).
     * Обработчик TimelineMessageHandler расшифровывает содержимое поданных
     * заявок и публикует событие tender.opened. Если bids_end не рассчитан
     * правилами — вскрытие не планируется.
     *
     * @param array<string, string> $timeline
     */
    public function scheduleBidOpening(Tender $tender, array $timeline): void
    {
        $bidsEnd = $timeline['bids_end'] ?? null;
        if (null === $bidsEnd) {
            return;
        }

        $runAt = new \DateTimeImmutable($bidsEnd, new \DateTimeZone('UTC'));
        $delayMs = max(0, ($runAt->getTimestamp() - time()) * 1000);

        $this->bus->dispatch(
            new TimelineMessage(
                aggregateType: 'tender',
                aggregateId: (string) $tender->getId(),
                action: TenderTimelineAction::OPEN_BIDS->value,
                runAt: $runAt,
                context: ['status' => $tender->getStatus()->value],
            ),
            [new DelayStamp($delayMs)],
        );
    }
}
