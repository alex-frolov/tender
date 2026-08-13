<?php

declare(strict_types=1);

namespace App\Contract\Service;

use App\Contract\ContractAccessChecker as ContractAccessCheckerContract;
use App\Contract\Entity\Enum\ContractStatusEnum;
use App\Contract\Repository\ContractRepository;
use App\Shared\Exception\ConflictException;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного контракта доступа к закрытым тендерам по договору
 * (см. App\Contract\ContractAccessChecker). Алиас импорта — имя класса
 * совпадает с именем интерфейса (PHP запрещает объявление класса с именем,
 * занятым `use`).
 *
 * assertCanParticipate() — жёсткая проверка (409 contract_required) для подачи
 * заявки; checkReason() — мягкая для GET /tenders/{id}/access (openapi: reason
 * enum contract_required/contract_expired/contract_terminated/ok).
 */
final readonly class ContractAccessChecker implements ContractAccessCheckerContract
{
    public function __construct(private ContractRepository $contracts)
    {
    }

    public function assertCanParticipate(Uuid $customerId, Uuid $supplierId): void
    {
        if (null === $this->contracts->findActiveMultiUse($customerId, $supplierId)) {
            throw new ConflictException('contract_required: supplier needs an active multi_use contract with the customer');
        }
    }

    public function checkReason(Uuid $customerId, Uuid $supplierId): string
    {
        $active = $this->contracts->findActiveMultiUse($customerId, $supplierId);
        if (null !== $active) {
            return 'ok';
        }

        $any = $this->contracts->findAnyMultiUse($customerId, $supplierId);
        if (null === $any) {
            return 'contract_required';
        }
        if (ContractStatusEnum::TERMINATED === $any->getStatus()) {
            return 'contract_terminated';
        }
        if (ContractStatusEnum::EXPIRED === $any->getStatus()) {
            return 'contract_expired';
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        if (null !== $any->getValidTo() && $any->getValidTo() < $today) {
            return 'contract_expired';
        }

        return 'contract_required';
    }
}
