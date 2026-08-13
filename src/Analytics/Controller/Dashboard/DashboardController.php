<?php

declare(strict_types=1);

namespace App\Analytics\Controller\Dashboard;

use App\Analytics\UseCase\GetDashboardUseCase;
use App\Controller\AbstractBaseController;
use App\Security\DashboardVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Дашборд компании (AM-13, GET /dashboard).
 *
 * Счётчики (активные тендеры / мои заявки / мои договоры) и ближайшие
 * дедлайны. Доступ — право dashboard.view (common, все роли компании).
 * Контракт: api/openapi.yaml. Query-параметр period (day/week/month) из спеки
 * принимается, но на снапшот-счётчики v1 не влияет (future period-тренды).
 */
final class DashboardController extends AbstractBaseController
{
    public const string URL = '/api/v1/dashboard';

    #[Route(self::URL, name: 'dashboard', methods: [Request::METHOD_GET])]
    #[IsGranted(DashboardVoter::VIEW)]
    public function dashboard(Request $request, GetDashboardUseCase $useCase): JsonResponse
    {
        $period = $request->query->get('period');
        if (null !== $period && !\in_array($period, ['day', 'week', 'month'], true)) {
            return $this->json(['title' => 'Validation Failed', 'code' => 'validation_error', 'detail' => 'invalid period'], 422);
        }

        return $this->json($useCase->execute($this->currentUser($request)));
    }
}
