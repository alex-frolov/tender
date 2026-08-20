<?php

declare(strict_types=1);

namespace App\Platform\Controller\Platform;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Platform\UseCase\GetUsageUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Потребление лимитов тенанта (FR-1.5.15, GET /usage?period=day|month).
 * requests/events/webhooks за период (audit_log + outbox + webhook_deliveries).
 * Доступ: admin компании (биллинговые данные). Контракт: api/openapi.yaml
 * (/usage GET).
 */
final class UsageController extends AbstractBaseController
{
    public const string URL = '/api/v1/usage';

    #[Route(self::URL, name: 'usage_get', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::ADMIN->value)]
    public function usage(Request $request, GetUsageUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute($user, $request->query->get('period')));
    }
}
