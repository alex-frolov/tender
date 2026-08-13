<?php

declare(strict_types=1);

namespace App\Analytics\Controller\Dashboard;

use App\Analytics\UseCase\GetTenderStatsUseCase;
use App\Controller\AbstractBaseController;
use App\Security\DashboardVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Статистика по тендерам (AM-13, GET /stats/tenders).
 *
 * Агрегаты по срезу dimension (region/customer/period; okpd2 в модели
 * отсутствует) за период [from, to): число тендеров, средний % снижения,
 * сумма цен договоров. Валидация среза/дат — в TenderStatsService (422).
 * Доступ — право dashboard.view (common). Контракт: api/openapi.yaml.
 */
final class TenderStatsController extends AbstractBaseController
{
    public const string URL = '/api/v1/stats/tenders';

    #[Route(self::URL, name: 'tender_stats', methods: [Request::METHOD_GET])]
    #[IsGranted(DashboardVoter::VIEW)]
    public function stats(Request $request, GetTenderStatsUseCase $useCase): JsonResponse
    {
        $dimension = $request->query->get('dimension', 'region');
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        return $this->json($useCase->execute(
            actor: $this->currentUser($request),
            dimension: \is_string($dimension) ? $dimension : 'region',
            from: \is_string($from) ? $from : null,
            to: \is_string($to) ? $to : null,
        ));
    }
}
