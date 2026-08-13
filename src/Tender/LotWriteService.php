<?php

declare(strict_types=1);

namespace App\Tender;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный write-контракт модуля Tender по лотам (мутации чужого лота из
 * других модулей — только через этот интерфейс, а не напрямую через объект
 * или репозиторий; границы модулей, PHPArkitect rule 6). Реализация —
 * App\Tender\Service\LotWriteService (внутри модуля Tender).
 */
interface LotWriteService
{
    /**
     * Закрытие лота (LotStatusEnum::CLOSED). Вызывается модулями-потребителями
     * (Contract) при завершении исполнения (DONE / DONE_BY_CLAIM), чтобы не
     * мутировать лот напрямую.
     *
     * @throws \App\Shared\Exception\NotFoundException если лот не найден
     */
    public function close(Uuid $lotId): void;

    /**
     * Фиксация заявки-победителя лота (lots.winner_bid_id = bids.id,
     * data-model.md). Вызывается модулем Auction при выборе победителя
     * (FR-1.3.5), чтобы не мутировать лот напрямую.
     *
     * @throws \App\Shared\Exception\NotFoundException если лот не найден
     */
    public function setWinnerBidId(Uuid $lotId, ?Uuid $winnerBidId): void;
}
