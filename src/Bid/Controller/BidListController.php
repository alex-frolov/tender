<?php

declare(strict_types=1);

namespace App\Bid\Controller;

use App\Bid\UseCase\ListBidsUseCase;
use App\Controller\AbstractBaseController;
use App\Security\BidVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список заявок тендера (AM-4, GET /tenders/{tenderId}/bids).
 * До вскрытия — только метаданные (FR-1.2.2); после вскрытия (FR-1.2.3,
 * UC-06) — расшифрованное содержимое (заказчик — полностью, участник —
 * part1). Оркестрация и презентация — ListBidsUseCase.
 * Доступ: право tenders.board.view через BidVoter.
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/bids GET).
 */
final class BidListController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/bids';

    #[Route(self::URL, name: 'bid_list', methods: [Request::METHOD_GET])]
    #[IsGranted(BidVoter::VIEW)]
    public function list(Request $request, string $tenderId, ListBidsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(user: $user, tenderId: $tenderId));
    }
}
