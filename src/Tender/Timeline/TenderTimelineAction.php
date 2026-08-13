<?php

declare(strict_types=1);

namespace App\Tender\Timeline;

/**
 * Действия отложенных задач таймлайна (FR-1.1.4).
 *
 * Значение используется как action в TimelineMessage; обработчик
 * TimelineMessageHandler сопоставляет его с переходом workflow тендера.
 */
enum TenderTimelineAction: string
{
    case START_BID_ACCEPTANCE = 'tender.start_bid_acceptance';
    case OPEN_BIDS = 'tender.open_bids';
}
