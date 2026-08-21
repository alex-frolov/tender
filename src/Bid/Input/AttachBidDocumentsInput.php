<?php

declare(strict_types=1);

namespace App\Bid\Input;

/**
 * Входные данные привязки документов к части 2 заявки
 * (FR-1.2.1, POST /bids/{bidId}/documents).
 *
 * Список заменяет прежний целиком: часть 2 — это состав приложений на момент
 * подачи, а не журнал добавлений. Пустой массив очищает часть 2.
 */
final class AttachBidDocumentsInput
{
    /** @var list<string> id документов, приложенных к этой заявке */
    public array $documentIds = [];
}
