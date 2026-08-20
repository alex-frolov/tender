<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\Form\LotCreateType;
use App\Tender\Input\LotCreateInput;
use App\Tender\UseCase\CreateTenderLotUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Добавление лота в тендер (FR-1.1.7, POST /tenders/{tenderId}/lots).
 * Только до окончания приёма заявок; после добавления пересчитывается инвариант
 * суммы лотов (409 при несовпадении с НМЦК). Доступ — право tenders.update
 * через TenderVoter (admin/manager; agent — 403).
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/lots POST).
 */
final class TenderLotCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/lots';

    #[Route(self::URL, name: 'tender_lot_create', methods: [Request::METHOD_POST])]
    #[IsGranted(TenderVoter::UPDATE)]
    public function create(Request $request, string $tenderId, CreateTenderLotUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        // Без strict: отсутствующие поля сохраняют дефолты DTO (tradeEndLeadHours=0
        // и т.п.), как при создании тендера (TenderCreateController).
        $form = $this->formInput(LotCreateType::class, $request);
        /** @var LotCreateInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            tenderId: $tenderId,
            input: $input,
            ip: $request->getClientIp(),
        ), Response::HTTP_CREATED);
    }
}
