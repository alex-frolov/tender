<?php

declare(strict_types=1);

namespace App\Bid;

/**
 * Публичный write-контракт модуля Bid: вскрытие заявок (FR-1.2.3, UC-06).
 * Вызывается автоматически по таймлайну (Tender/Timeline,
 * TimelineMessage на bids_end), кросс-модульно — только через этот интерфейс
 * (границы модулей, PHPArkitect rule 6). Реализация —
 * App\Bid\Service\BidOpeningService (внутри модуля Bid).
 */
interface BidOpeningService
{
    /**
     * Вскрытие заявок тендера (FR-1.2.3): расшифровка содержимого поданных
     * заявок, фиксация tenders.bids_opened_at, событие tender.opened через
     * outbox. Идемпотентно: повторный вызов — no-op.
     *
     * @param string      $tenderId id тендера
     * @param string|null $ip       IP актора для аудита (null = система)
     */
    public function open(string $tenderId, ?string $ip = null): void;
}
