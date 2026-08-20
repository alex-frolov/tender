<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Фильтры списка договоров (GET /contracts, query-параметр contract_status).
 *
 * DTO, наполняемый формой ContractListFiltersType из query-параметров.
 * Публичные nullable-поля — data_class формы (конвенция Input).
 */
final class ContractListFiltersInput
{
    public ?string $contractStatus = null;
}
