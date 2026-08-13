<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\Form\AuctionCancelType;
use App\Auction\Input\CancelAuctionInput;
use App\Auction\UseCase\CancelAuctionUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отмена аукциона (→ CANCELLED, POST /auctions/{auctionId}/cancel).
 *
 * Отмена из любого допускающего статуса (T7/T9/T12/T14/T19/T22/T25/T28/T32);
 * причина — необязательный текст (в аудит и событие auction.cancelled).
 * Тонкий access-адаптер → CancelAuctionUseCase. Доступ: право auction.control
 * через AuctionVoter (admin/manager; agent — 403); выполняет только заказчик
 * (тенант тендера). Контракт: api/openapi.yaml.
 */
final class AuctionCancelController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/cancel';

    #[Route(self::URL, name: 'auction_cancel', methods: [Request::METHOD_POST])]
    #[IsGranted(AuctionVoter::CANCEL)]
    public function cancel(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        CancelAuctionUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);
        $form = $this->formInput(AuctionCancelType::class, $request);
        /** @var CancelAuctionInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            auction: $auction,
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
