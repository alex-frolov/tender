<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\UseCase\MarkAuctionDoneByPerformerUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ExecutionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отметка выполнения исполнителем (T30, IN_WORK → DONE_BY_PERFORMER).
 *
 * Тонкий access-адаптер → MarkAuctionDoneByPerformerUseCase (кросс-модульный
 * вызов ContractExecutionService — публичный контракт Contract-модуля).
 * contract_tenders.status → done_by_performer. Доступ: execution.manage
 * (supplier); party-проверка (победитель) — в ContractExecutionService через
 * UseCase.
 */
final class AuctionMarkDoneController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/mark-done';

    #[Route(self::URL, name: 'auction_mark_done', methods: [Request::METHOD_POST])]
    #[IsGranted(ExecutionVoter::MARK_DONE_BY_PERFORMER)]
    public function markDone(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        MarkAuctionDoneByPerformerUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(
            auction: $auction,
            user: $user,
            ip: $request->getClientIp(),
        ));
    }
}
