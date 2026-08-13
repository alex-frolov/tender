<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\UseCase\StartAuctionWorkUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ExecutionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Начало работ по договору (T26, APPROVE → IN_WORK, FR-1.4.3).
 *
 * Тонкий access-адаптер → StartAuctionWorkUseCase (кросс-модульный вызов
 * ContractExecutionService — публичный контракт Contract-модуля). Исполнитель
 * (победитель аукциона) отмечает старт работ; contract_tenders.status →
 * in_work. Доступ: execution.manage (supplier) / auction.control (customer);
 * party-проверка — в ContractExecutionService через UseCase.
 */
final class AuctionStartWorkController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/start-work';

    #[Route(self::URL, name: 'auction_start_work', methods: [Request::METHOD_POST])]
    #[IsGranted(ExecutionVoter::START_WORK)]
    public function startWork(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        StartAuctionWorkUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(
            auction: $auction,
            user: $user,
            ip: $request->getClientIp(),
        ));
    }
}
