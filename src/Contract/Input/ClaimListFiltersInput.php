<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Фильтры списка претензий (GET /claims: contract_id, status).
 *
 * DTO, наполняемый формой ClaimListFiltersType из query-параметров.
 * Публичные nullable-поля — data_class формы (конвенция Input).
 */
final class ClaimListFiltersInput
{
    public ?string $contractId = null;

    public ?string $status = null;
}
