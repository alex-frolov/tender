<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\Form\TenderCancelType;
use App\Tender\Input\CancelTenderInput;
use App\Tender\UseCase\CancelTenderUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отмена тендера с причиной (FR-1.1.8): любой активный/withdrawn статус →
 * cancelled. Код причины обязателен; при code=other — свободный текст. Причина
 * сохраняется в тендере, аудите и событии tender.cancelled. Доступ: право
 * tenders.cancel через TenderVoter (admin/manager; agent — 403). Принадлежность
 * компании (tenant-изоляция) и бизнес-правила — TenderService через
 * CancelTenderUseCase. Контракт: api/openapi.yaml (/tenders/{tenderId}/cancel POST).
 */
final class TenderCancelController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/cancel';

    #[Route(self::URL, name: 'tender_cancel', methods: [Request::METHOD_POST])]
    #[IsGranted(TenderVoter::CANCEL)]
    public function cancel(Request $request, string $tenderId, CancelTenderUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(TenderCancelType::class, $request);
        /** @var CancelTenderInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            tenderId: $tenderId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
