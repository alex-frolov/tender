<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Входные данные добавления лота в тендер (FR-1.1.7, POST /tenders/{tenderId}/lots).
 * Заполняется формой LotCreateType из JSON-тела; обязательные поля (title,
 * price_net_minor) — constraints в форме. vat_rate/price_basis/currency, если
 * не заданы, наследуются от тендера в сервисе (как при создании тендера).
 *
 * Наследует поля LotInput (number для добавления не используется — номер
 * назначается следующим по порядку в TenderService::addLot).
 */
final class LotCreateInput extends LotInput
{
}
