<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\UseCase\GetAuctionStateUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionStreamVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Состояние аукциона (FR-1.3.1, GET /auctions/{auctionId}/state).
 *
 * Query-access-адаптер → GetAuctionStateUseCase. Статус + правила
 * (rules_snapshot) + таймер (remaining_sec) + текущие цены; источник
 * истины — PostgreSQL (auctions); live-поля актуальны на момент запроса.
 * Доступ — R7 (AuctionStreamVoter::VIEW): допущенные участники, заказчик,
 * наблюдатели (platform_admin). Контракт: api/openapi.yaml.
 */
final class AuctionStateController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/state';

    #[Route(self::URL, name: 'auction_state', methods: [Request::METHOD_GET])]
    #[IsGranted(AuctionStreamVoter::VIEW, subject: 'auction')]
    public function state(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        GetAuctionStateUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute($auction));
    }
}
