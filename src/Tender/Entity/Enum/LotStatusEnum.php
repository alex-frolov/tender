<?php

declare(strict_types=1);

namespace App\Tender\Entity\Enum;

/**
 * Статус лота (data-model.md, domain/tender-state-machine.md).
 * Повторяет фазы тендера — по статусам лотов агрегируется статус тендера
 * (вариант C «бутылочное горлышко», FR-1.1.3). Переходы — через workflow.
 */
enum LotStatusEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ACCEPTING_BIDS = 'accepting_bids';
    case BIDDING = 'bidding';
    case EVALUATION = 'evaluation';
    case AWARDING = 'awarding';
    case CONTRACT = 'contract';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    public function phase(): int
    {
        return match ($this) {
            self::DRAFT => 0,
            self::PUBLISHED => 1,
            self::ACCEPTING_BIDS => 2,
            self::BIDDING => 3,
            self::EVALUATION => 4,
            self::AWARDING => 5,
            self::CONTRACT => 6,
            self::CLOSED => 7,
            self::CANCELLED => 7,
        };
    }

    /**
     * Круг видимости лота в этом статусе (FR-1.5.14) — та же матрица, что
     * у тендера (TenderStatusEnum::visibilityLevel), в тех же терминах:
     *   draft                                         — только заказчик;
     *   published, accepting_bids, bidding,
     *   evaluation, awarding, contract                — участники;
     *   closed, cancelled                             — заказчик и исполнитель.
     *
     * Собственная матрица нужна потому, что статус тендера — «бутылочное
     * горлышко» лотов (FR-1.1.3, вариант C): пока хоть один лот в работе,
     * тендер виден рынку, но завершённый (closed) или отменённый лот внутри
     * него — уже дело заказчика и исполнителя этого лота, а не остальных.
     * Уровень PARTICIPANTS здесь безусловен: access_type проверен на уровне
     * тендера, к лотам видимого тендера повторно он не применяется.
     */
    public function visibilityLevel(): TenderVisibilityLevelEnum
    {
        return match ($this) {
            self::DRAFT => TenderVisibilityLevelEnum::OWNER_ONLY,
            self::PUBLISHED, self::ACCEPTING_BIDS, self::BIDDING,
            self::EVALUATION, self::AWARDING, self::CONTRACT => TenderVisibilityLevelEnum::PARTICIPANTS,
            self::CLOSED, self::CANCELLED => TenderVisibilityLevelEnum::OWNER_AND_WINNER,
        };
    }

    public function isTerminal(): bool
    {
        return self::CLOSED === $this || self::CANCELLED === $this;
    }
}
