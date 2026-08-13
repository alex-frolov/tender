<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\UseCase\GetAuctionStreamUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionStreamVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Discovery SSE-стрима аукциона (FR-1.3.4, ADR-003).
 *
 * `GET /auctions/{id}/stream` возвращает JWT-ссылку discovery на Mercure hub:
 * клиент подключается через EventSource к приватному topic `auction:{id}`
 * с полученным subscribe-JWT. Тонкий access-адаптер → GetAuctionStreamUseCase.
 * Доступ: допущенные участники, заказчик, наблюдатели (AuctionStreamVoter, R7).
 * Контракт: api/openapi.yaml.
 */
final class AuctionStreamController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/stream';

    #[Route(self::URL, name: 'auction_stream', methods: [Request::METHOD_GET])]
    #[IsGranted(AuctionStreamVoter::SUBSCRIBE, subject: 'auction')]
    public function streamAuction(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        GetAuctionStreamUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute($auction));
    }
}
