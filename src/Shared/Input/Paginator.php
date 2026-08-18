<?php

declare(strict_types=1);

namespace App\Shared\Input;

/**
 * Пагинация списков API (keyset-курсор, AR-6/NFR-22).
 *
 * DTO, наполняемый формой PaginatorForm из query-параметров
 * ?limit=&cursor=. limit — размер страницы (1..100, default 20);
 * cursor — OPAQUE-курсор из предыдущего ответа (null — первая страница).
 * Публичные nullable-поля — data_class формы PaginatorForm (конвенция Input).
 */
final class Paginator
{
    public ?int $limit = null;

    public ?string $cursor = null;

    /**
     * Нормализованный лимит: default 20, клампится в 1..100 в форме.
     */
    public function limitValue(): int
    {
        if (null === $this->limit || $this->limit < 1) {
            return 20;
        }

        return min($this->limit, 100);
    }
}
