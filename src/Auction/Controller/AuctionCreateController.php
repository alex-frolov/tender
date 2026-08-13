<?php

declare(strict_types=1);

namespace App\Auction\Controller;

use App\Auction\Form\AuctionCreateType;
use App\Auction\Input\CreateAuctionInput;
use App\Auction\UseCase\CreateAuctionUseCase;
use App\Controller\AbstractBaseController;
use App\Security\AuctionVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание аукциона по лоту (FR-1.3, POST /auctions).
 *
 * Аукцион создаётся из лота тендера заказчика: канонические параметры
 * (база/НДС/стартовая цена) наследуются от лота, параметры торгов — из тела;
 * статус new (или scheduled при scheduled_start_at). Тонкий access-адаптер →
 * CreateAuctionUseCase. Доступ: право auction.control через AuctionVoter
 * (admin/manager; agent — 403); tenant-проверка в сервисе.
 * Контракт: api/openapi.yaml.
 */
final class AuctionCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/auctions';

    #[Route(self::URL, name: 'auction_create', methods: [Request::METHOD_POST])]
    #[IsGranted(AuctionVoter::CREATE)]
    public function create(
        Request $request,
        CreateAuctionUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);
        $form = $this->formInput(AuctionCreateType::class, $request, strict: true);
        /** @var CreateAuctionInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ), Response::HTTP_CREATED);
    }
}
