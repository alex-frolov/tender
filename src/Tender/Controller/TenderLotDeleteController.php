<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\UseCase\RemoveTenderLotUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Удаление лота (FR-1.1.7, DELETE /tenders/{tenderId}/lots/{lotId}).
 * Только до окончания приёма заявок; удалять последний лот нельзя (тендер без
 * лотов не может быть опубликован). Доступ — право tenders.update через
 * TenderVoter (admin/manager; agent — 403).
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/lots/{lotId} DELETE).
 */
final class TenderLotDeleteController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/lots/{lotId}';

    #[Route(self::URL, name: 'tender_lot_delete', methods: [Request::METHOD_DELETE])]
    #[IsGranted(TenderVoter::UPDATE)]
    public function delete(Request $request, string $tenderId, string $lotId, RemoveTenderLotUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $useCase->execute(
            user: $user,
            tenderId: $tenderId,
            lotId: $lotId,
            ip: $request->getClientIp(),
        );

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
