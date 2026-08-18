<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\Form\LotUpdateType;
use App\Tender\Input\LotUpdateInput;
use App\Tender\UseCase\UpdateTenderLotUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Изменение лота (FR-1.1.7, PATCH /tenders/{tenderId}/lots/{lotId}).
 * Правка допустимых полей до окончания приёма заявок; после правки
 * пересчитывается инвариант суммы лотов (409 при несовпадении с НМЦК).
 * Доступ — право tenders.update через TenderVoter (admin/manager; agent — 403).
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/lots/{lotId} PATCH).
 */
final class TenderLotUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/lots/{lotId}';

    #[Route(self::URL, name: 'tender_lot_update', methods: [Request::METHOD_PATCH])]
    #[IsGranted(TenderVoter::UPDATE)]
    public function update(Request $request, string $tenderId, string $lotId, UpdateTenderLotUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(LotUpdateType::class, $request, strict: true);
        /** @var LotUpdateInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            tenderId: $tenderId,
            lotId: $lotId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
