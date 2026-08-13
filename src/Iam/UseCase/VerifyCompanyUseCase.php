<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\User;
use App\Iam\Input\CompanyVerifyInput;
use App\Iam\Service\CompanyVerificationService;

/**
 * Модерация компании суперадмином (FR-1.5.7, POST /companies/{companyId}/verify).
 *
 * Только platform_admin (атрибут #[IsGranted] на контроллере). Разбор action
 * и reason, переходы по workflow `company_verification` выполняет доменный
 * CompanyVerificationService; ошибки (ValidationException/CompanyNotFound
 * Exception/StateTransitionException) бросаются как ApiException → подписчик.
 * Презентация {id, verification_status, verified_at} формируется в UseCase
 * (фиксированный статус 200 проставляет контроллер).
 */
final readonly class VerifyCompanyUseCase implements IamUseCase
{
    public function __construct(private CompanyVerificationService $companies)
    {
    }

    /**
     * @return array{id: string, verification_status: string, verified_at: ?string}
     */
    public function execute(User $user, string $companyId, CompanyVerifyInput $input, ?string $ip = null): array
    {
        $company = $this->companies->verify(
            $user,
            $companyId,
            $input->action,
            (string) $input->reason,
            $ip,
        );

        return [
            'id' => (string) $company->getId(),
            'verification_status' => $company->getVerificationStatus()->value,
            'verified_at' => $company->getVerifiedAt()?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
