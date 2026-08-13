<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\UseCase\ReleaseSecurityUseCase;
use App\Controller\AbstractBaseController;
use App\Security\SecurityVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Возврат обеспечения (FR-1.4.1/1.4.2, POST /securities/{securityId}/release).
 * Только активное обеспечение; сторона (заказчик/исполнитель) — в
 * SecurityService через ReleaseSecurityUseCase.
 */
final class SecurityReleaseController extends AbstractBaseController
{
    public const string URL = '/api/v1/securities/{securityId}/release';

    #[Route(self::URL, name: 'security_release', methods: [Request::METHOD_POST])]
    #[IsGranted(SecurityVoter::RELEASE)]
    public function release(Request $request, string $securityId, ReleaseSecurityUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(
            user: $user,
            securityId: $securityId,
            ip: $request->getClientIp(),
        ));
    }
}
