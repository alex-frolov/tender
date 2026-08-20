<?php

declare(strict_types=1);

namespace App\ProcurementPlan\Controller;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\ProcurementPlan\Form\ProcurementPlanCreateType;
use App\ProcurementPlan\Input\ProcurementPlanCreateInput;
use App\ProcurementPlan\UseCase\CreateProcurementPlanUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание плана закупок (FR-1.5.6, POST /procurement-plans).
 * Только admin компании. Валидацию тела выполняет ProcurementPlanCreateType
 * (422 при невалидных). Контракт: api/openapi.yaml (/procurement-plans POST).
 */
final class ProcurementPlanCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/procurement-plans';

    #[Route(self::URL, name: 'procurement_plan_create', methods: [Request::METHOD_POST])]
    #[IsGranted(UserRoleEnum::ADMIN->value)]
    public function create(Request $request, CreateProcurementPlanUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(ProcurementPlanCreateType::class, $request);
        /** @var ProcurementPlanCreateInput $input */
        $input = $form->getData();

        return $this->json(
            $useCase->execute($user, $input, $request->getClientIp()),
            Response::HTTP_CREATED,
        );
    }
}
