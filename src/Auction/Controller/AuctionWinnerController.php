<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\Form\AuctionWinnerType;
use App\Auction\Input\SelectWinnerInput;
use App\Auction\UseCase\ChooseWinnerUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Выбор победителя аукциона (FR-1.3.5, POST /auctions/{auctionId}/winner).
 *
 * Тонкий access-адаптер: валидация body формой AuctionWinnerType (422),
 * передача валидированного DTO в ChooseWinnerUseCase (прикладной слой),
 * который диспетчит авто (REDUCTION) / ручной (FREE_PRICE/PRICE_REQUEST)
 * режим. Доступ: право auction.choose_winner через AuctionVoter (admin/manager;
 * agent — 403); выполняет только заказчик (тенант тендера) — в доменном
 * AuctionWinnerService через UseCase. Контракт: api/openapi.yaml.
 */
final class AuctionWinnerController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/winner';

    #[Route(self::URL, name: 'auction_choose_winner', methods: [Request::METHOD_POST])]
    #[IsGranted(AuctionVoter::CHOOSE_WINNER)]
    public function chooseWinner(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        ChooseWinnerUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        $form = $this->formInput(AuctionWinnerType::class, $request);
        /** @var SelectWinnerInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            auction: $auction,
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
