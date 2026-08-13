<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\UseCase\FinishAuctionUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Завершение торгов (FR-1.3.5, T16, POST /auctions/{auctionId}/finish).
 *
 * TRADE → CHOICE: торги остановлены (ручной стоп заказчика или инициация
 * авто-завершения), окно закрыто, ставки больше не принимаются; событие
 * auction.finished. Тонкий access-адаптер → FinishAuctionUseCase. Доступ:
 * право auction.control через AuctionVoter (admin/manager; agent — 403);
 * выполняет только заказчик (тенант тендера). Контракт: api/openapi.yaml.
 */
final class AuctionFinishController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/finish';

    #[Route(self::URL, name: 'auction_finish', methods: [Request::METHOD_POST])]
    #[IsGranted(AuctionVoter::FINISH)]
    public function finish(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        FinishAuctionUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(
            auction: $auction,
            user: $user,
            ip: $request->getClientIp(),
        ));
    }
}
