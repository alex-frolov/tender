<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\Company;
use App\Iam\Input\CompanySearchInput;
use App\Iam\Presenter\CompanyPresenter;
use App\Iam\Repository\CompanyRepository;
use App\Shared\Exception\ValidationException;

/**
 * Поиск компании-контрагента по названию или ИНН (GET /companies/search).
 *
 * Нужен там, где в запрос уходит чужой `company_id`: создание договора,
 * привязка процедуры. Без поиска идентификатор приходилось узнавать вне
 * интерфейса, а реестр компаний доступен только суперадмину.
 *
 * Отдаются только подтверждённые компании и только краткая карточка
 * (id, название, ИНН, тип) — реквизиты для выбора контрагента не нужны.
 */
final readonly class SearchCompaniesUseCase implements IamUseCase
{
    /** Сколько подсказок отдавать, если клиент не попросил иначе. */
    private const int DEFAULT_LIMIT = 10;

    /** Минимальная длина запроса — та же, что в форме. */
    private const int MIN_QUERY_LENGTH = 2;

    public function __construct(
        private CompanyRepository $companies,
        private CompanyPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     *
     * @throws ValidationException если запрос пуст или короче двух символов
     */
    public function execute(CompanySearchInput $input): array
    {
        // Проверка продублирована намеренно. Форма валидирует только те поля,
        // которые реально пришли в query (submit с clearMissing: false), поэтому
        // при полном отсутствии ?q= её constraint не сработает — а пустой запрос
        // превратил бы поиск в выгрузку всех подтверждённых компаний, то есть
        // в реестр, которого этот эндпоинт давать не должен.
        $query = trim($input->q);
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            throw new ValidationException('q must be at least 2 characters long');
        }

        $companies = $this->companies->search($query, $input->limit ?? self::DEFAULT_LIMIT);

        return [
            'items' => array_map(
                fn (Company $company): array => $this->presenter->brief($company),
                $companies,
            ),
        ];
    }
}
