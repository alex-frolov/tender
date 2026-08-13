<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\UseCase\ForfeitSecurityUseCase;
use App\Controller\AbstractBaseController;
use App\Security\SecurityVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Удержание обеспечения (нарушение, FR-1.4.1/1.4.2, POST /securities/{securityId}/forfeit).
 * Только активное обеспечение; только заказчик — в SecurityService через
 * ForfeitSecurityUseCase.
 */
final class SecurityForfeitController extends AbstractBaseController
{
    public const string URL = '/api/v1/securities/{securityId}/forfeit';

    #[Route(self::URL, name: 'security_forfeit', methods: [Request::METHOD_POST])]
    #[IsGranted(SecurityVoter::FORFEIT)]
    public function forfeit(Request $request, string $securityId, ForfeitSecurityUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(
            user: $user,
            securityId: $securityId,
            ip: $request->getClientIp(),
        ));
    }
}
