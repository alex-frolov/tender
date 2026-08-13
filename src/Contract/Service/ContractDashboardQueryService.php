<?php

declare(strict_types=1);

namespace App\Contract\Service;

use App\Contract\ContractDashboardQuery;
use App\Contract\Repository\ContractRepository;
use App\Contract\Repository\ContractTenderRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта статистики договоров (AM-13).
 */
final readonly class ContractDashboardQueryService implements ContractDashboardQuery
{
    public function __construct(
        private ContractRepository $contracts,
        private ContractTenderRepository $contractTenders,
    ) {
    }

    public function countForCompany(Uuid $companyId): int
    {
        return $this->contracts->countForCompany($companyId);
    }

    public function amountSumByTender(Uuid $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->contractTenders->amountSumByTender($tenantId, $from, $to);
    }
}
