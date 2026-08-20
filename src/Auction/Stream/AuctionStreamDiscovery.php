<?php

declare(strict_types=1);

namespace App\Auction\Stream;

use App\Auction\Entity\Auction;
use App\Auction\State\AuctionStateService;
use App\Auction\State\AuctionStateSnapshot;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

/**
 * Discovery SSE-стрима аукциона (FR-1.3.4, ADR-003).
 *
 * `GET /auctions/{id}/stream` возвращает JWT-ссылку discovery на hub:
 * клиент подключается к hub через EventSource с полученным subscribe-JWT
 * (приватный topic `auction:{id}`, право sub) и получает live-события.
 *
 * Ответ:
 *   hub        — публичный URL hub (MERCURE_PUBLIC_URL);
 *   topic      — приватный topic `auction:{id}`;
 *   token      — subscribe-JWT (mercure.subscribe = [topic], подписан subscribe-секретом);
 *   expires_in — срок жизни токена (сек);
 *   state      — текущий снапшот live-состояния (для немедленного рендера).
 *
 * `GET /auctions/stream` (discoverMany) — тот же discovery для СПИСКА аукционов
 * компании: один JWT с правом sub на все topic'и сразу, чтобы список торгов
 * обновлялся по одному соединению с hub, а не по одному EventSource на строку
 * (браузер держит не больше ~6 SSE-соединений на origin).
 */
final class AuctionStreamDiscovery
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly int $subscribeTtl,
        private readonly AuctionStateService $state,
    ) {
    }

    /**
     * Discovery-ответ для аукциона.
     *
     * @return array<string, mixed>
     */
    public function discover(Auction $auction): array
    {
        $topic = AuctionTopic::for((string) $auction->getId());

        return [
            'hub' => $this->hub->getPublicUrl(),
            'topic' => $topic,
            'token' => $this->subscribeToken([$topic]),
            'expires_in' => $this->subscribeTtl,
            'state' => $this->stateSnapshot($auction)->toArray(),
        ];
    }

    /**
     * Discovery-ответ для списка аукционов (GET /auctions/stream): один hub,
     * приватные topic'и всех переданных аукционов и ОДИН subscribe-JWT с правом
     * sub на каждый из них. Пустой список — пустые topics и токен без прав:
     * подписываться не на что (живых торгов у компании сейчас нет).
     *
     * @param list<Auction> $auctions
     *
     * @return array<string, mixed>
     */
    public function discoverMany(array $auctions): array
    {
        $topics = array_map(
            static fn (Auction $auction): string => AuctionTopic::for((string) $auction->getId()),
            $auctions,
        );

        return [
            'hub' => $this->hub->getPublicUrl(),
            'topics' => $topics,
            'token' => $this->subscribeToken($topics),
            'expires_in' => $this->subscribeTtl,
        ];
    }

    /**
     * Subscribe-JWT на приватные topic'и (mercure.subscribe = topics).
     * JWT подписывается subscribe-секретом (MERCURE_JWT_SECRET_SUBSCRIBE);
     * hub проверяет право sub при подключении (R7).
     *
     * @param list<string> $topics
     */
    private function subscribeToken(array $topics): string
    {
        $factory = $this->hub->getFactory();
        if (!$factory instanceof TokenFactoryInterface) {
            throw new \LogicException('Mercure hub has no subscribe token factory configured');
        }

        return $factory->create($topics, []);
    }

    /**
     * Текущий live-снапшот (Redis) или, при его отсутствии, снапшот из сущности
     * (аукцион ещё не в live / Redis недоступен) — для стартового рендера state.
     */
    private function stateSnapshot(Auction $auction): AuctionStateSnapshot
    {
        return $this->state->read($auction->getId())
            ?? AuctionStateSnapshot::fromEntity($auction);
    }
}
