<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\Form\AuctionUpdateType;
use App\Auction\Input\UpdateAuctionInput;
use App\Auction\UseCase\UpdateAuctionUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Правка параметров аукциона до старта торгов (PATCH /auctions/{auctionId},
 * FR-1.3.1).
 *
 * PATCH-семантика: меняются только переданные поля (тип/шаг/лимиты/
 * длительность/продления); канонические поля из лота не редактируются.
 * Тонкий access-адаптер → UpdateAuctionUseCase. Доступ: право auction.control
 * через AuctionVoter (admin/manager; agent — 403); tenant-проверка и статус
 * «до торгов» — в сервисе. Контракт: api/openapi.yaml.
 */
final class AuctionUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}';

    #[Route(self::URL, name: 'auction_update', methods: [Request::METHOD_PATCH])]
    #[IsGranted(AuctionVoter::UPDATE)]
    public function update(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        UpdateAuctionUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);
        // DTO с маркером NOT_SET: различаем «поле не передано» и явный null (сброс).
        $form = $this->formInput(AuctionUpdateType::class, $request, data: new UpdateAuctionInput());
        /** @var UpdateAuctionInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            auction: $auction,
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
