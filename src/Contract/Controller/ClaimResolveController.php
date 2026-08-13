<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Form\ResolveClaimType;
use App\Contract\Input\ResolveClaimInput;
use App\Contract\UseCase\ResolveClaimUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ClaimVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Урегулирование претензии (FR-1.4.5, POST /claims/{claimId}/resolve).
 * outcome: rejected/settled → IN_WORK; accepted → DONE_BY_CLAIM;
 * terminate_contract → CANCELLED. Только заказчик (claims.manage).
 * Валидацию body выполняет форма ResolveClaimType (422), оркестрацию и
 * презентацию — ResolveClaimUseCase.
 * Контракт: api/openapi.yaml (/claims/{claimId}/resolve POST).
 */
final class ClaimResolveController extends AbstractBaseController
{
    public const string URL = '/api/v1/claims/{claimId}/resolve';

    #[Route(self::URL, name: 'claim_resolve', methods: [Request::METHOD_POST])]
    #[IsGranted(ClaimVoter::MANAGE)]
    public function resolve(Request $request, string $claimId, ResolveClaimUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(ResolveClaimType::class, $request);
        /** @var ResolveClaimInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            claimId: $claimId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
