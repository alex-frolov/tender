<?php

declare(strict_types=1);

namespace App\Supplier\UseCase;

use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Supplier\Input\SupplierProfileUpdateInput;
use App\Supplier\Presenter\SupplierProfilePresenter;
use App\Supplier\Service\SupplierProfileService;

/**
 * Обновление профиля поставщика (FR-1.5.5, PUT /suppliers/profile).
 *
 * Доступ (только admin компании) — декларативно в контроллере через
 * SupplierVoter::UPDATE_PROFILE (см. AGENTS.md). Здесь — только бизнес-логика
 * и защита от отсутствия привязки к компании. Lazy-создание профиля при первом
 * сохранении (GET не пишет в БД).
 */
final readonly class UpdateSupplierProfileUseCase implements SupplierUseCase
{
    public function __construct(
        private SupplierProfileService $profiles,
        private SupplierProfilePresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация профиля (openapi SupplierProfile)
     *
     * @throws ConflictException если актор без компании
     */
    public function execute(User $user, SupplierProfileUpdateInput $input, ?string $ip = null): array
    {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        $profile = $this->profiles->update($companyId, $input, (string) $user->getId(), $ip);

        return $this->presenter->single($profile, $this->profiles->companyOf($profile));
    }
}
