<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Entity\ContractTender;
use App\Contract\Form\ContractStageCreateType;
use App\Contract\Input\ContractStageCreateInput;
use App\Contract\UseCase\CreateContractStageUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ContractVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание этапа исполнения по тендеру (FR-1.4.3, UC-10,
 * POST /contract_tenders/{contractTenderId}/stages).
 * Обе стороны договора (ContractVoter::STAGE); party-проверка и номер по
 * умолчанию — ContractStageService. Контракт: api/openapi.yaml
 * (/contract_tenders/{contractTenderId}/stages POST).
 */
final class ContractStageCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/contract_tenders/{contractTenderId}/stages';

    #[Route(self::URL, name: 'contract_stage_create', methods: [Request::METHOD_POST])]
    #[IsGranted(ContractVoter::STAGE, subject: 'contractTender')]
    public function create(
        Request $request,
        #[MapEntity(mapping: ['contractTenderId' => 'id'])]
        ContractTender $contractTender,
        CreateContractStageUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        $form = $this->formInput(ContractStageCreateType::class, $request);
        /** @var ContractStageCreateInput $input */
        $input = $form->getData();

        return $this->json(
            $useCase->execute($user, $contractTender, $input, $request->getClientIp()),
            Response::HTTP_CREATED,
        );
    }
}
