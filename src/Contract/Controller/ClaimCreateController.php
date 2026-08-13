<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Form\CreateClaimType;
use App\Contract\Input\CreateClaimInput;
use App\Contract\UseCase\CreateClaimUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ClaimVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание претензии (FR-1.4.5, POST /claims). Только заказчик (claims.manage).
 * stage APPROVE/IN_WORK/DONE_BY_PERFORMER → аукцион CLAIM (работы приостановлены).
 * Валидацию body выполняет форма CreateClaimType (422), оркестрацию и
 * презентацию — CreateClaimUseCase (прикладной слой модуля).
 * Контракт: api/openapi.yaml (/claims POST).
 */
final class ClaimCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/claims';

    #[Route(self::URL, name: 'claim_create', methods: [Request::METHOD_POST])]
    #[IsGranted(ClaimVoter::CREATE)]
    public function create(Request $request, CreateClaimUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(CreateClaimType::class, $request);
        /** @var CreateClaimInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ), Response::HTTP_CREATED);
    }
}
