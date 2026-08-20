<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\UseCase\ListTenderLotsUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Лоты тендера (FR-1.1.1, GET /tenders/{tenderId}/lots).
 * Доступ: право tenders.board.view через TenderVoter; принадлежность компании
 * (tenant-изоляция) — TenderService через ListTenderLotsUseCase (404 для чужого).
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/lots GET).
 */
final class TenderLotsController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/lots';

    #[Route(self::URL, name: 'tender_lots', methods: [Request::METHOD_GET])]
    #[IsGranted(TenderVoter::VIEW)]
    public function lots(Request $request, string $tenderId, ListTenderLotsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(user: $user, tenderId: $tenderId));
    }
}
