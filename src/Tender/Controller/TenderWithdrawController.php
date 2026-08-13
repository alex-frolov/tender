<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\Form\TenderWithdrawType;
use App\Tender\Input\WithdrawTenderInput;
use App\Tender\UseCase\WithdrawTenderUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отзыв публикации (B3, FR-1.1.3): published → withdrawn, только до старта
 * приёма заявок. Причина (reason) обязательна. Доступ: право tenders.withdraw
 * через TenderVoter (admin/manager; agent — 403). Принадлежность компании
 * (tenant-изоляция) и бизнес-правила — TenderService через WithdrawTenderUseCase.
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/withdraw POST).
 */
final class TenderWithdrawController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/withdraw';

    #[Route(self::URL, name: 'tender_withdraw', methods: [Request::METHOD_POST])]
    #[IsGranted(TenderVoter::WITHDRAW)]
    public function withdraw(Request $request, string $tenderId, WithdrawTenderUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(TenderWithdrawType::class, $request);
        /** @var WithdrawTenderInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            tenderId: $tenderId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
