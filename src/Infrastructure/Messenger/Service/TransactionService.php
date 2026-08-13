<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Обёртка над транзакциями БД для middleware.
 */
final class TransactionService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function inActiveTransaction(): bool
    {
        return $this->em->getConnection()->getTransactionNestingLevel() > 0;
    }

    public function beginTransaction(): void
    {
        $this->em->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->em->getConnection()->commit();
    }

    public function rollBack(): void
    {
        $this->em->getConnection()->rollBack();
    }
}
