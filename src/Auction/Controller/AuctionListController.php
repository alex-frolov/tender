<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\UseCase\ListAuctionsUseCase;
use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список аукционов компании (FR-1.3, GET /auctions).
 *
 * Query-access-адаптер → ListAuctionsUseCase. Тенант — компания актора
 * (tenant-изоляция); доступ — право tenders.board.view (все роли компании),
 * через TenderVoter (единый код для просмотра тендерной доски).
 * Контракт: api/openapi.yaml (/auctions GET).
 */
final class AuctionListController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions';

    #[Route(self::URL, name: 'auction_list', methods: [Request::METHOD_GET])]
    #[IsGranted(TenderVoter::VIEW)]
    public function list(Request $request, ListAuctionsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute($user));
    }
}
