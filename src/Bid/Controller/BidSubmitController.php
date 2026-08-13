<?php

declare(strict_types=1);

namespace App\Bid\Controller;

use App\Bid\Form\BidCreateType;
use App\Bid\Input\CreateBidInput;
use App\Bid\UseCase\SubmitBidUseCase;
use App\Controller\AbstractBaseController;
use App\Security\BidVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Подача/замена заявки (FR-1.2.1/1.2.5, POST /tenders/{tenderId}/bids).
 * Доступ: право bids.submit через BidVoter (admin/manager; agent — 403).
 * Валидацию body выполняет форма BidCreateType (422), оркестрацию —
 * SubmitBidUseCase (прикладной слой модуля): резолвит тендер публично,
 * шифрует содержимое до вскрытия (FR-1.2.2). Повторная подача на лот = замена.
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/bids POST).
 */
final class BidSubmitController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/bids';

    #[Route(self::URL, name: 'bid_submit', methods: [Request::METHOD_POST])]
    #[IsGranted(BidVoter::SUBMIT)]
    public function submit(Request $request, string $tenderId, SubmitBidUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(BidCreateType::class, $request);
        /** @var CreateBidInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            tenderId: $tenderId,
            input: $input,
            ip: $request->getClientIp(),
        ), 201);
    }
}
