<?php

declare(strict_types=1);

namespace App\Complaint\Input;

/**
 * Фильтры списка жалоб (GET /complaints: tender_id, status).
 *
 * DTO, наполняемый формой ComplaintListFiltersType из query-параметров.
 * Публичные nullable-поля — data_class формы (конвенция Input).
 */
final class ComplaintListFiltersInput
{
    public ?string $tenderId = null;

    public ?string $status = null;
}
