<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\UseCase\GetAuctionsStreamUseCase;
use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Discovery SSE-стрима списка аукционов (FR-1.3.4, ADR-003).
 *
 * `GET /auctions/stream` возвращает JWT-ссылку discovery на Mercure hub сразу
 * на все живые аукционы компании: hub + приватные topic'и `auction:{id}` +
 * один subscribe-JWT. Клиент открывает ОДИН EventSource со всеми topic'ами и
 * обновляет строки списка по `auction_id` из полезной нагрузки события.
 *
 * Тонкий access-адаптер → GetAuctionsStreamUseCase. Доступ — право
 * tenders.board.view (все роли компании), как у самого списка /auctions;
 * tenant-изоляция — в use-case. Контракт: api/openapi.yaml.
 */
final class AuctionsStreamController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/stream';

    #[Route(self::URL, name: 'auction_list_stream', methods: [Request::METHOD_GET])]
    #[IsGranted(TenderVoter::VIEW)]
    public function streamAuctions(Request $request, GetAuctionsStreamUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute($user));
    }
}
