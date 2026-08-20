<?php

declare(strict_types=1);

namespace App\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный контракт модуля Contract: доступ к закрытым тендерам по договору
 * (contract_holders, FR-1.5.14). Участвовать в закрытом тендере может только
 * исполнитель, у которого с заказчиком (tender.customer_id) заключён
 * действующий multi_use-договор. Кросс-модульные проверки (Bid при подаче
 * заявки, Tender на входе) — только через этот интерфейс (границы модулей,
 * PHPArkitect rule 6). Реализация —
 * App\Contract\Service\ContractAccessChecker.
 */
interface ContractAccessChecker
{
    /**
     * @throws \App\Shared\Exception\ConflictException если у исполнителя нет действующего
     *                                                 multi_use-договора с заказчиком
     *                                                 (409 contract_required, openapi)
     */
    public function assertCanParticipate(Uuid $customerId, Uuid $supplierId): void;

    /**
     * Причина отсутствия доступа (GET /tenders/{id}/access): ok — есть действующий
     * договор; contract_required — договора нет; contract_expired — договор истёк
     * (по valid_to или статус expired); contract_terminated — договор расторгнут.
     */
    public function checkReason(Uuid $customerId, Uuid $supplierId): string;

    /**
     * Заказчики, с которыми у компании есть действующий multi_use-договор
     * (FR-1.5.14). Нужен видимости закрытых тендеров: каталог фильтруется
     * условием tenders.customer_id IN (...), а не проверкой договора на каждую
     * строку (N+1 в списке недопустим, NFR-22).
     *
     * @return list<Uuid> id компаний-заказчиков (пусто — доступа по договору нет)
     */
    public function customersWithActiveMultiUse(Uuid $supplierId): array;
}
