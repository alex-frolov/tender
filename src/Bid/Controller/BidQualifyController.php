<?php

declare(strict_types=1);

namespace App\Bid\Controller;

use App\Bid\Form\BidQualifyType;
use App\Bid\Input\QualifyBidInput;
use App\Bid\UseCase\QualifyBidUseCase;
use App\Controller\AbstractBaseController;
use App\Security\BidVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Допуск/отклонение заявки (FR-1.2.4, UC-05, POST /bids/{bidId}/qualification).
 * Доступ: право bids.qualify через BidVoter (admin/manager; agent — 403);
 * рассмотрение выполняет только заказчик (тенант тендера) — BidService через
 * QualifyBidUseCase. Отклонение — с уведомлением участника (письмо).
 * Валидацию body выполняет форма BidQualifyType (422).
 * Контракт: api/openapi.yaml (/bids/{bidId}/qualification POST).
 */
final class BidQualifyController extends AbstractBaseController
{
    public const string URL = '/api/v1/bids/{bidId}/qualification';

    #[Route(self::URL, name: 'bid_qualify', methods: [Request::METHOD_POST])]
    #[IsGranted(BidVoter::QUALIFY)]
    public function qualify(Request $request, string $bidId, QualifyBidUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(BidQualifyType::class, $request);
        /** @var QualifyBidInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            bidId: $bidId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
