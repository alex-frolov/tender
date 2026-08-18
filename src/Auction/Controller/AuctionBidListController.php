<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\UseCase\ListBidsUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionStreamVoter;
use App\Shared\Form\PaginatorForm;
use App\Shared\Input\Paginator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * История ставок аукциона (AM-5, GET /auctions/{auctionId}/bids).
 *
 * Query-access-адаптер → ListBidsUseCase (read-модель без мутаций).
 * Анонимность bidder_id («анонимно до конца торгов») — в UseCase/Presenter.
 * Доступ — R7 (AuctionStreamVoter::VIEW): допущенные участники, заказчик,
 * наблюдатели (platform_admin). Контракт: api/openapi.yaml.
 */
final class AuctionBidListController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/bids';

    #[Route(self::URL, name: 'auction_bid_list', methods: [Request::METHOD_GET])]
    #[IsGranted(AuctionStreamVoter::VIEW, subject: 'auction')]
    public function list(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        ListBidsUseCase $useCase,
    ): JsonResponse {
        $paginatorForm = $this->formQuery(PaginatorForm::class, $request);
        /** @var Paginator $paginator */
        $paginator = $paginatorForm->getData();

        return $this->json($useCase->execute($auction, $paginator));
    }
}
