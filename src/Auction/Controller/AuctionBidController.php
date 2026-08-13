<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\Form\AuctionBidType;
use App\Auction\Input\PlaceAuctionBidInput;
use App\Auction\UseCase\PlaceBidUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Подача ставки в аукционе (FR-1.3.2/1.3.8, POST /auctions/{auctionId}/bids).
 *
 * Тонкий access-адаптер: валидация body формой AuctionBidType (422), передача
 * валидированного DTO в PlaceBidUseCase (прикладной слой модуля). Механика
 * ставки по типу аукциона + идемпотентность (Idempotency-Key, AR-4) — в
 * доменном AuctionBidService через UseCase. Доступ: право auction.bid через
 * AuctionVoter (admin/manager; agent — 403); ответ — ставка вызывающего
 * участника (bidder_id виден). Контракт: api/openapi.yaml.
 */
final class AuctionBidController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/bids';

    #[Route(self::URL, name: 'auction_bid', methods: [Request::METHOD_POST])]
    #[IsGranted(AuctionVoter::BID)]
    public function place(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        PlaceBidUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        $form = $this->formInput(AuctionBidType::class, $request);
        /** @var PlaceAuctionBidInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            auction: $auction,
            user: $user,
            input: $input,
            idempotencyKey: $request->headers->get('Idempotency-Key'),
            ip: $request->getClientIp(),
        ), 201);
    }
}
