<?php

declare(strict_types=1);

namespace App\Tender\Entity\Enum;

/**
 * Переходы workflow лота (config/workflow/lot.yaml, FR-1.1.3/1.1.7).
 * Значения соответствуют именам переходов в конфигурации.
 *
 * Лот — единица реального процесса закупки: заявки подаются на лот, торгуется
 * лот, исполняется лот. Фазы лота задают двое:
 *   - тендер (административно) — публикация, старт приёма заявок, отмена;
 *   - аукцион лота — торги и исполнение (AuctionStatusEnum::lotTransition()).
 * Обратно по этим же фазам агрегируется статус тендера (вариант C
 * «бутылочное горлышко», domain/tender-state-machine.md раздел 3).
 *
 * Переходы применяются только через symfony/workflow: внутри модуля —
 * App\Tender\Service\LotPhaseService, кросс-модульно — публичный контракт
 * App\Tender\LotWriteService::applyTransition().
 */
enum LotStatusTransition: string
{
    /** Публикация тендера: draft → published. */
    case PUBLISH = 'lot_publish';

    /** Старт приёма заявок на участие (bids_start): published → accepting_bids. */
    case START_BID_ACCEPTANCE = 'lot_start_bid_acceptance';

    /**
     * Старт торгов: accepting_bids → bidding, а у тендера без заявок на участие
     * (bids_required=false) — сразу published → bidding.
     */
    case START_TRADE = 'lot_start_trade';

    /** Торги завершены, идёт выбор победителя: bidding → evaluation. */
    case START_EVALUATION = 'lot_start_evaluation';

    /** Победитель утверждён: evaluation → awarding. */
    case START_AWARDING = 'lot_start_awarding';

    /** Начало работ по договору: awarding → contract. */
    case START_CONTRACT = 'lot_start_contract';

    /** Исполнение принято заказчиком: contract → closed. */
    case CLOSE = 'lot_close';

    /** Отмена лота (каскад отмены тендера, отмена/истечение аукциона). */
    case CANCEL = 'lot_cancel';
}
