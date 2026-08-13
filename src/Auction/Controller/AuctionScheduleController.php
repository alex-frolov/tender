<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Entity\Auction;
use App\Auction\Form\AuctionScheduleType;
use App\Auction\Input\ScheduleAuctionInput;
use App\Auction\UseCase\ScheduleAuctionUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Планирование старта торгов (T10, NEW → SCHEDULED, POST /auctions/{auctionId}/schedule).
 *
 * Фиксирует scheduled_start_at (обязательно в будущем); событие
 * auction.scheduled. Тонкий access-адаптер → ScheduleAuctionUseCase. Доступ:
 * право auction.control через AuctionVoter (admin/manager; agent — 403);
 * выполняет только заказчик (тенант тендера). Контракт: api/openapi.yaml.
 */
final class AuctionScheduleController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions/{auctionId}/schedule';

    #[Route(self::URL, name: 'auction_schedule', methods: [Request::METHOD_POST])]
    #[IsGranted(AuctionVoter::SCHEDULE)]
    public function schedule(
        Request $request,
        #[MapEntity(mapping: ['auctionId' => 'id'])]
        Auction $auction,
        ScheduleAuctionUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);
        $form = $this->formInput(AuctionScheduleType::class, $request, strict: true);
        /** @var ScheduleAuctionInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            auction: $auction,
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
