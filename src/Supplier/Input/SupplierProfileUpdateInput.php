<?php

declare(strict_types=1);

namespace App\Supplier\Input;

/**
 * Входные данные обновления профиля поставщика (FR-1.5.5, PUT /suppliers/profile).
 * categories — категории/виды работ; capabilities — возможности/лицензии;
 * documents — id приложенных документов (uuid). Все поля опциональны
 * (пустой массив очищает значение).
 */
final class SupplierProfileUpdateInput
{
    /** @var list<string> */
    public array $categories = [];

    /** @var list<string> */
    public array $capabilities = [];

    /** @var list<string> */
    public array $documents = [];
}
