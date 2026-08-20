<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Repository\AuctionRepository;
use App\Auction\Stream\AuctionStreamDiscovery;
use App\Iam\Entity\User;
use App\Shared\Input\InputValue;

/**
 * Discovery SSE-стрима СПИСКА аукционов компании (FR-1.3.4, ADR-003).
 *
 * Query-use-case: один JWT-ответ discovery на все живые аукционы тенанта, чтобы
 * список торгов (GET /auctions) обновлялся по ОДНОМУ соединению с hub. Иначе
 * страница открывала бы по EventSource на строку, а браузер держит не больше
 * ~6 SSE-соединений на origin — при десятке идущих торгов список замирал бы.
 *
 * Тенант — компания актора: в topics попадают только СВОИ аукционы, поэтому
 * отдельная проверка на каждый аукцион (AuctionStreamVoter) здесь не нужна —
 * заказчик своего тендера входит в круг подписчиков по определению.
 *
 * Осознанное отличие от ListAuctionsUseCase и от подписки на конкретный аукцион
 * (GET /auctions/{id}/stream): те расширены до видимости тендера (FR-1.5.14), а
 * агрегированный токен — нет. Иначе каждый пользователь платформы подписывался
 * бы разом на все идущие открытые торги, и fan-out хаба рос бы как
 * «пользователи × живые аукционы». Цена решения: чужие строки в GET /auctions
 * не обновляются live — актуальные цены по ним видны на карточке аукциона.
 *
 * Живые статусы — SCHEDULED/TRADE/PAUSED: только по ним hub публикует события
 * (ставки, таймер, смена статуса). Завершённые и неназначенные торги в topics
 * не попадают: подписка на них ничего не даёт.
 */
final readonly class GetAuctionsStreamUseCase implements AuctionUseCase
{
    /** Статусы, по которым ядро публикует live-события в hub. */
    private const array LIVE_STATUSES = [
        AuctionStatusEnum::SCHEDULED,
        AuctionStatusEnum::TRADE,
        AuctionStatusEnum::PAUSED,
    ];

    public function __construct(
        private AuctionRepository $auctions,
        private AuctionStreamDiscovery $discovery,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        $companyId = InputValue::companyId($user);

        $live = array_values(array_filter(
            $this->auctions->listForTenant($companyId),
            static fn (Auction $auction): bool => \in_array($auction->getStatus(), self::LIVE_STATUSES, true),
        ));

        return $this->discovery->discoverMany($live);
    }
}
