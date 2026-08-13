<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\UseCase\ConfirmAuctionDoneUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ExecutionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Подтверждение выполнения заказчиком (T27/T31/T34, → DONE).
 *
 * Тонкий access-адаптер → ConfirmAuctionDoneUseCase (кросс-модульный вызов
 * ContractExecutionService — публичный контракт Contract-модуля).
 * **B2: только при наличии действительного договора** (signed/registered);
 * contract_tenders.status → done. Доступ: auction.control (customer);
 * tenant-проверка и B2 — в ContractExecutionService через UseCase.
 */
final class AuctionConfirmDoneController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/confirm-done';

    #[Route(self::URL, name: 'auction_confirm_done', methods: [Request::METHOD_POST])]
    #[IsGranted(ExecutionVoter::CONFIRM_DONE)]
    public function confirmDone(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        ConfirmAuctionDoneUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(
            auction: $auction,
            user: $user,
            ip: $request->getClientIp(),
        ));
    }
}
