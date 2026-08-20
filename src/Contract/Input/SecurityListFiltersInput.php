<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Фильтры списка обеспечения (GET /securities: kind, status).
 *
 * DTO, наполняемый формой SecurityListFiltersType из query-параметров.
 * Публичные nullable-поля — data_class формы (конвенция Input).
 */
final class SecurityListFiltersInput
{
    public ?string $kind = null;

    public ?string $status = null;
}
