<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\UseCase\ListTendersUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список тендеров компании (FR-1.1.1). Фильтры: ?status=, ?limit=, ?cursor=.
 * Пагинация — keyset-курсор (AR-6, NFR-22): limit 1..100 (default 20), cursor —
 * OPAQUE-курсор из предыдущего ответа; ответ — {items, next_cursor}.
 * Доступ: право tenders.board.view (admin/manager/agent) через TenderVoter.
 * Оркестрация и презентация — ListTendersUseCase.
 * Контракт: api/openapi.yaml (/tenders GET).
 */
final class TenderListController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders';

    #[Route(self::URL, name: 'tender_list', methods: [Request::METHOD_GET])]
    #[IsGranted(TenderVoter::VIEW)]
    public function list(Request $request, ListTendersUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $status = $request->query->get('status');
        $limit = $request->query->get('limit');
        $cursor = $request->query->get('cursor');

        return $this->json($useCase->execute(
            user: $user,
            status: \is_string($status) ? $status : null,
            limit: \is_string($limit) ? $limit : null,
            cursor: \is_string($cursor) ? $cursor : null,
        ));
    }
}
