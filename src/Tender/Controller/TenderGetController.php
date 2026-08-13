<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\UseCase\GetTenderUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Карточка тендера (FR-1.1.1). Доступ: право tenders.board.view через TenderVoter;
 * принадлежность компании (tenant-изоляция) — TenderService через GetTenderUseCase
 * (404 для чужого). Контракт: api/openapi.yaml (/tenders/{tenderId} GET).
 */
final class TenderGetController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}';

    #[Route(self::URL, name: 'tender_get', methods: [Request::METHOD_GET])]
    #[IsGranted(TenderVoter::VIEW)]
    public function get(Request $request, string $tenderId, GetTenderUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(user: $user, tenderId: $tenderId));
    }
}
