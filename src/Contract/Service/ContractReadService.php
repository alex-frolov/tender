<?php

declare(strict_types=1);

namespace App\Contract\Service;

use App\Contract\ContractReadService as ContractReadServiceContract;
use App\Contract\Repository\ContractRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта модуля Contract (см.
 * App\Contract\ContractReadService). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 *
 * Делегирует App\Contract\Repository\ContractRepository — единственный владелец выборок
 * по contracts внутри модуля; другие модули видят только этот сервис.
 */
final readonly class ContractReadService implements ContractReadServiceContract
{
    public function __construct(private ContractRepository $contracts)
    {
    }

    public function isParty(Uuid $contractId, Uuid $companyId): bool
    {
        $contract = $this->contracts->findById((string) $contractId);
        if (null === $contract) {
            return false;
        }

        return $contract->getCustomerId()->equals($companyId)
            || $contract->getSupplierId()->equals($companyId);
    }
}
