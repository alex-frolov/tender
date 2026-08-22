<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Фильтры списка договоров (GET /contracts, query-параметры contract_status
 * и tender_id).
 *
 * tender_id отбирает договоры, привязанные к процедуре (contract_tenders):
 * без него страница аукциона не может ответить на вопрос «есть ли по этому
 * лоту договор» — пришлось бы вычитывать весь список договоров компании.
 *
 * DTO, наполняемый формой ContractListFiltersType из query-параметров.
 * Публичные nullable-поля — data_class формы (конвенция Input).
 */
final class ContractListFiltersInput
{
    public ?string $contractStatus = null;

    public ?string $tenderId = null;
}
