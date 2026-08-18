<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Входные данные создания тендера (FR-1.1.1).
 * Заполняется формой TenderCreateType из JSON-тела POST /tenders; тендер
 * создаётся в статусе draft. Обязательные поля (title, procedure_type,
 * customer_id, currency, price_basis) и валидация enum/диапазонов — constraints
 * в форме; преобразование enum, генерация number и бизнес-правила — в сервисе.
 */
final class CreateTenderInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $title = '';

    public ?string $description = null;

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $procedureType = '';

    public ?string $lawType = null;

    public ?int $nmckMinor = null;

    public bool $noStartPrice = false;

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $currency = '';

    public ?float $vatRate = null;

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $priceBasis = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $customerId = '';

    public ?string $region = null;

    public ?string $okpd2 = null;

    public ?string $accessType = null;

    public ?string $requiredContractTypeId = null;

    /** @var array<string, string>|null ключевые даты таймлайна */
    public ?array $timeline = null;

    /** @var list<LotInput> */
    public array $lots = [];
}
