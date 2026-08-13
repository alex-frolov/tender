<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\Form\TenderUpdateType;
use App\Tender\Input\UpdateTenderInput;
use App\Tender\UseCase\UpdateTenderUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Изменение тендера до окончания приёма заявок (FR-1.1.1). Только с правом
 * tenders.update (admin/manager; agent — 403) через TenderVoter. Валидацию
 * входных данных выполняет форма TenderUpdateType, оркестрацию —
 * UpdateTenderUseCase (прикладной слой модуля).
 * Контракт: api/openapi.yaml (/tenders/{tenderId} PATCH).
 */
final class TenderUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}';

    #[Route(self::URL, name: 'tender_update', methods: [Request::METHOD_PATCH])]
    #[IsGranted(TenderVoter::UPDATE)]
    public function update(Request $request, string $tenderId, UpdateTenderUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(TenderUpdateType::class, $request);
        /** @var UpdateTenderInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            tenderId: $tenderId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
