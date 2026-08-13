<?php

declare(strict_types=1);

namespace App\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный read-контракт модуля Contract (межмодульные проверки по договорам).
 *
 * Другие модули (Document и т.п.) НЕ ходят в ContractRepository напрямую —
 * только через этот интерфейс (корневой контракт модуля, PHPArkitect rule 6).
 * Реализация — App\Contract\Service\ContractReadService (внутри модуля Contract).
 */
interface ContractReadService
{
    /**
     * Принадлежность компании к договору (заказчик или исполнитель, FR-1.4.3).
     * Возвращает false, если договор не найден или компания не является стороной.
     */
    public function isParty(Uuid $contractId, Uuid $companyId): bool;
}
