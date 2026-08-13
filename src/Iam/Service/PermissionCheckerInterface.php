<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\User;

/**
 * Контракт проверки права (FR-1.5.10/1.5.15) для Voter'ов и сервисов.
 *
 * Реализация по умолчанию — PermissionCheckService. Выделен в интерфейс, чтобы
 * Voter'ы зависели от абстракции (App\Security\TenderVoter и др.) и были
 * тестируемы без реального кэша/БД.
 */
interface PermissionCheckerInterface
{
    public function can(User $user, string $permissionCode): bool;
}
