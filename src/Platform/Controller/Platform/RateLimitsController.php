<?php

declare(strict_types=1);

namespace App\Platform\Controller\Platform;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Platform\UseCase\GetRateLimitsUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Текущие лимиты API (FR-1.5.15, GET /rate-limits).
 * global — общий лимит (api_global), per_tender — auction_bids/tender_reads;
 * значения «peek» (consume(0)) — без расхода токенов. Доступ: любой сотрудник
 * компании (agent — минимальная роль). Контракт: api/openapi.yaml (/rate-limits GET).
 */
final class RateLimitsController extends AbstractBaseController
{
    public const string URL = '/api/v1/rate-limits';

    #[Route(self::URL, name: 'rate_limits_get', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function limits(Request $request, GetRateLimitsUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request)));
    }
}
