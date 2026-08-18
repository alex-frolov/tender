<?php

declare(strict_types=1);

namespace App\Analytics\Controller\Dashboard;

use App\Analytics\Form\DashboardQueryType;
use App\Analytics\Input\DashboardQueryInput;
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
 * Query-параметр period (day/week/month) — форма DashboardQueryType:
 * ограничивает горизонт upcoming_deadlines (ближайшие 1 день/7 дней/30 дней);
 * счётчики — снапшот-мгновенные и от period не зависят.
 * Контракт: api/openapi.yaml.
 */
final class DashboardController extends AbstractBaseController
{
    public const string URL = '/api/v1/dashboard';

    #[Route(self::URL, name: 'dashboard', methods: [Request::METHOD_GET])]
    #[IsGranted(DashboardVoter::VIEW)]
    public function dashboard(Request $request, GetDashboardUseCase $useCase): JsonResponse
    {
        $queryForm = $this->formQuery(DashboardQueryType::class, $request);
        /** @var DashboardQueryInput $query */
        $query = $queryForm->getData();

        return $this->json($useCase->execute(
            actor: $this->currentUser($request),
            period: $query->period,
        ));
    }
}
