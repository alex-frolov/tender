<?php

declare(strict_types=1);

namespace App\Bid\Controller;

use App\Bid\Form\BidWithdrawType;
use App\Bid\Input\WithdrawBidInput;
use App\Bid\UseCase\WithdrawBidUseCase;
use App\Controller\AbstractBaseController;
use App\Security\BidVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отзыв заявки (FR-1.2.5, POST /bids/{bidId}/withdraw). Доступ: право
 * bids.withdraw через BidVoter (admin/manager; agent — 403); владение заявкой
 * (supplierId = компания актора) и «только до окончания приёма» — BidService
 * через WithdrawBidUseCase. Валидацию body выполняет форма BidWithdrawType (422).
 * Контракт: api/openapi.yaml (/bids/{bidId}/withdraw POST).
 */
final class BidWithdrawController extends AbstractBaseController
{
    public const string URL = '/api/v1/bids/{bidId}/withdraw';

    #[Route(self::URL, name: 'bid_withdraw', methods: [Request::METHOD_POST])]
    #[IsGranted(BidVoter::WITHDRAW)]
    public function withdraw(Request $request, string $bidId, WithdrawBidUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(BidWithdrawType::class, $request);
        /** @var WithdrawBidInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            bidId: $bidId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
