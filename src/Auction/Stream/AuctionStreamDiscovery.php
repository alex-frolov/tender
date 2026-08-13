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
            'token' => $this->subscribeToken($topic),
            'expires_in' => $this->subscribeTtl,
            'state' => $this->stateSnapshot($auction)->toArray(),
        ];
    }

    /**
     * Subscribe-JWT на приватный topic (mercure.subscribe = [topic]).
     * JWT подписывается subscribe-секретом (MERCURE_JWT_SECRET_SUBSCRIBE);
     * hub проверяет право sub при подключении (R7).
     */
    private function subscribeToken(string $topic): string
    {
        $factory = $this->hub->getFactory();
        if (!$factory instanceof TokenFactoryInterface) {
            throw new \LogicException('Mercure hub has no subscribe token factory configured');
        }

        return $factory->create([$topic], []);
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
