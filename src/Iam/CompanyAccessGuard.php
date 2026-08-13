<?php

declare(strict_types=1);

namespace App\Iam;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный контракт модуля Iam: проверка org_pending-ограничения (FR-1.5.7).
 *
 * Переиспользуется всеми функциями, требующими подтверждённой компании:
 * создание/публикация тендеров (заказчик, Tender), подача заявок и ставки
 * (исполнитель, Bid/Auction), документы (Document). Кросс-модульные вызовы —
 * только через этот интерфейс (границы модулей, PHPArkitect rule 6).
 * Реализация — App\Iam\Service\CompanyAccessGuard.
 */
interface CompanyAccessGuard
{
    /**
     * Бросить OrgPendingException, если компания не подтверждена (не active).
     *
     * @throws Exception\OrgPendingException если компании нет или она не active
     */
    public function assertActive(Uuid $companyId): void;

    public function isActive(Uuid $companyId): bool;
}
