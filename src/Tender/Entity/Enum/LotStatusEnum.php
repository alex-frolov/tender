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

    public function isTerminal(): bool
    {
        return self::CLOSED === $this || self::CANCELLED === $this;
    }
}
