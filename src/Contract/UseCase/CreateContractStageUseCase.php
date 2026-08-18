<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\Entity\ContractTender;
use App\Contract\Input\ContractStageCreateInput;
use App\Contract\Presenter\ContractStagePresenter;
use App\Contract\Service\ContractStageService;
use App\Iam\Entity\User;

/**
 * Создание этапа исполнения по тендеру (FR-1.4.3, UC-10,
 * POST /contract_tenders/{contractTenderId}/stages).
 *
 * Вход — валидированный ContractStageCreateInput (форма ContractStageCreateType),
 * party-проверка и номер по умолчанию — ContractStageService::create,
 * ответ — ContractStagePresenter::single (openapi ContractStage).
 * Доступ: право contracts.sign через ContractVoter::STAGE (subject ContractTender).
 */
final readonly class CreateContractStageUseCase implements ContractUseCase
{
    public function __construct(
        private ContractStageService $stages,
        private ContractStagePresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация этапа (openapi ContractStage)
     */
    public function execute(User $user, ContractTender $contractTender, ContractStageCreateInput $input, ?string $ip = null): array
    {
        $stage = $this->stages->create($user, (string) $contractTender->getId(), $input, $ip);

        return $this->presenter->single($stage);
    }
}
