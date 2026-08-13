<?php

declare(strict_types=1);

namespace App\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный read-контракт статистики договоров для дашборда/аналитики (AM-13).
 * Потребители других модулей (App\Analytics) обращаются через этот
 * интерфейс, а не через ContractRepository/ContractTenderRepository
 * (границы модулей, rule 6). Реализация —
 * App\Contract\Service\ContractDashboardQueryService.
 */
interface ContractDashboardQuery
{
    /**
     * Число договоров компании (как заказчика или исполнителя) — счётчик
     * дашборда my_contracts (GET /dashboard).
     */
    public function countForCompany(Uuid $companyId): int;

    /**
     * Сумма цен договоров по тендеру за период [from, to) (GET /stats/tenders
     * contracts_amount_sum_minor): сумма price_net_minor привязок
     * contract_tenders по договорам компании, созданным в период.
     *
     * @return array<string, int> tender_id → сумма цен (minor units)
     */
    public function amountSumByTender(Uuid $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
