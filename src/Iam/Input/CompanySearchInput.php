<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Параметры поиска компании (GET /companies/search: q, limit).
 *
 * `q` обязателен и не короче двух символов: выдача «всех компаний площадки»
 * по пустому запросу — это реестр, а он доступен только суперадмину
 * (GET /admin/companies).
 */
final class CompanySearchInput
{
    public string $q = '';

    public ?int $limit = null;
}
