<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Entity\Contract;
use App\Contract\Form\BindTenderType;
use App\Contract\Input\BindTenderInput;
use App\Contract\UseCase\BindTenderUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ContractVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Привязка тендера к договору (FR-1.4.6, POST /contracts/{contractId}/tenders).
 * Многоразовый (multi_use) — несколько тендеров на один договор; одноразовый
 * (single_use) — только один. Выполняет заказчик (contracts.create). Цена/условия
 * по тендеру фиксируются в contract_tenders (status=pending). Валидацию body
 * выполняет форма BindTenderType (422), оркестрацию — BindTenderUseCase.
 * Контракт: api/openapi.yaml (/contracts/{contractId}/tenders POST).
 */
final class ContractBindTenderController extends AbstractBaseController
{
    public const string URL = '/api/v1/contracts/{contractId}/tenders';

    #[Route(self::URL, name: 'contract_bind_tender', methods: [Request::METHOD_POST])]
    #[IsGranted(ContractVoter::BIND_TENDER)]
    public function bind(
        Request $request,
        #[MapEntity(mapping: ['contractId' => 'id'])]
        Contract $contract,
        BindTenderUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        $form = $this->formInput(BindTenderType::class, $request);
        /** @var BindTenderInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            contract: $contract,
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ), Response::HTTP_CREATED);
    }
}
