<?php

declare(strict_types=1);

namespace App\Tender\Entity\Enum;

/**
 * Переходы состояния тендера (domain/tender-state-machine.md, FR-1.1.3/1.1.4).
 * Имена переходов для symfony/workflow (config/workflow/tender.yaml).
 *
 * Переходы публикации/авто-таймлайна/отзыва/отмены (задачи 2.3/2.4) +
 * переходы агрегации при мультилоте: при изменении статусов
 * лотов тендер переводится на соответствующую фазу через TenderStatusAggregator.
 * Перепубликация withdrawn → published (B3) — тоже здесь.
 */
enum TenderStatusTransition: string
{
    case PUBLISH = 'publish';
    case START_BID_ACCEPTANCE = 'start_bid_acceptance';
    case WITHDRAW = 'withdraw';
    case CANCEL = 'cancel';
    case REPUBLISH = 'republish';
    case START_TRADE = 'start_trade';
    case START_EVALUATION = 'start_evaluation';
    case START_AWARDING = 'start_awarding';
    case START_CONTRACT = 'start_contract';
    case CLOSE = 'close';
}
