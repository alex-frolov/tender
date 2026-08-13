<?php

declare(strict_types=1);

namespace App\Tender\Entity\Enum;

/**
 * Статус тендера (FR-1.1.3, domain/tender-state-machine.md):
 * draft → published → accepting_bids → bidding → evaluation → awarding →
 * contract → closed; допускаются withdrawn (отзыв до старта приёма) и cancelled.
 * При мультилоте агрегируется по лотам (вариант C «бутылочное горлышко»).
 * Переходы — только через symfony/workflow (config/workflow/tender.yaml).
 */
enum TenderStatusEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case WITHDRAWN = 'withdrawn';
    case ACCEPTING_BIDS = 'accepting_bids';
    case BIDDING = 'bidding';
    case EVALUATION = 'evaluation';
    case AWARDING = 'awarding';
    case CONTRACT = 'contract';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    /**
     * Фаза для агрегации статуса при мультилоте (FR-1.1.3, вариант C):
     * чем больше фаза, тем «продвинутее» лот; не-терминальный минимум
     * определяет статус тендера.
     */
    public function phase(): int
    {
        return match ($this) {
            self::DRAFT => 0,
            self::PUBLISHED => 1,
            self::WITHDRAWN => 1,
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
