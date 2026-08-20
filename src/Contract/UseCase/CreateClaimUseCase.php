<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ClaimService;
use App\Contract\Input\CreateClaimInput;
use App\Contract\Presenter\ClaimPresenter;
use App\Iam\Entity\User;

/**
 * Создание претензии (FR-1.4.5, POST /claims).
 *
 * Только заказчик (claims.manage); stage APPROVE/IN_WORK/DONE_BY_PERFORMER →
 * аукцион CLAIM (работы приостановлены). Вход — валидированный CreateClaimInput
 * (форма CreateClaimType), оркестрация — ClaimService::create. Ответ —
 * полная карточка претензии (openapi Claim).
 */
final readonly class CreateClaimUseCase implements ContractUseCase
{
    public function __construct(
        private ClaimService $claims,
        private ClaimPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация претензии (openapi Claim)
     */
    public function execute(User $user, CreateClaimInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->claims->create($user, $input, $ip));
    }
}
