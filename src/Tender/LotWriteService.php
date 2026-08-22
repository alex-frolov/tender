<?php

declare(strict_types=1);

namespace App\Tender;

use App\Tender\Entity\Enum\LotStatusTransition;
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
     * Применить переход фазы лота (config/workflow/lot.yaml). Вызывается
     * модулем Auction: реальный процесс идёт на уровне лота, и его фаза
     * следует за статусом аукциона (AuctionStatusEnum::lotTransition()).
     *
     * **Идемпотентен:** недопустимый из текущего статуса переход молча
     * пропускается — вызывающий не обязан знать, где лот сейчас.
     *
     * @param bool $flush сохранить изменение сразу; false — вызывающий
     *                    сохранит его вместе со своей транзакцией
     *
     * @throws \App\Shared\Exception\NotFoundException если лот не найден
     */
    public function applyTransition(Uuid $lotId, LotStatusTransition $transition, bool $flush = true): void;

    /**
     * Фиксация заявки-победителя лота (lots.winner_bid_id = bids.id,
     * data-model.md). Вызывается модулем Auction при выборе победителя
     * (FR-1.3.5), чтобы не мутировать лот напрямую.
     *
     * @throws \App\Shared\Exception\NotFoundException если лот не найден
     */
    public function setWinnerBidId(Uuid $lotId, ?Uuid $winnerBidId): void;
}
