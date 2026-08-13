<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\ActiveNotificationSubscriptionsProviderInterface;
use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\Entity\NotificationSubscription;
use App\Notification\NotificationMatcher;
use App\Shared\Events\EventMessage;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Матчинг доменного события на подписки уведомлений (FR-1.6.2/1.6.3):
 * - мгновенный матчинг (digest=false) по каналу/типу события;
 * - дайджест-матчинг (digest=true) по каналу/типу события;
 * - payload-фильтры (тендер/лот) должны совпадать полностью (FR-1.6.3);
 * - не-email каналы в email-матчинг не попадают;
 * - подписка на другой тип события не матчится (событие фильтруется в PHP).
 */
final class NotificationMatcherTest extends TestCase
{
    private NotificationMatcher $matcher;

    /** @var ActiveNotificationSubscriptionsProviderInterface&Stub */
    private ActiveNotificationSubscriptionsProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = self::createStub(ActiveNotificationSubscriptionsProviderInterface::class);
        $this->matcher = new NotificationMatcher($this->provider);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function subscription(
        NotificationChannelEnum $channel,
        string $event,
        ?array $filters = null,
        bool $digest = false,
    ): NotificationSubscription {
        return new NotificationSubscription(
            userId: Uuid::v4(),
            tenantId: Uuid::v4(),
            channel: $channel,
            events: [$event],
            filters: $filters,
            digest: $digest,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(string $type, array $payload = []): EventMessage
    {
        return EventMessage::create(
            eventType: $type,
            tenantId: (string) Uuid::v4(),
            aggregateType: 'tender',
            aggregateId: 'tender-1',
            payload: $payload,
        );
    }

    public function testMatchesInstantSubscriptionByEventTypeAndChannel(): void
    {
        $sub = $this->subscription(NotificationChannelEnum::EMAIL, 'tender.published');
        $this->provider->method('findActiveForChannelAndDigest')->willReturn([$sub]);

        $matched = $this->matcher->matchInstant($this->event('tender.published'), NotificationChannelEnum::EMAIL);

        self::assertSame([$sub], $matched);
    }

    public function testSkipsInstantSubscriptionWithDifferentEventType(): void
    {
        $sub = $this->subscription(NotificationChannelEnum::EMAIL, 'tender.published');
        $this->provider->method('findActiveForChannelAndDigest')->willReturn([$sub]);

        $matched = $this->matcher->matchInstant($this->event('tender.cancelled'), NotificationChannelEnum::EMAIL);

        self::assertSame([], $matched);
    }

    public function testSkipsNonEmailChannelInEmailMatch(): void
    {
        $webhook = $this->subscription(NotificationChannelEnum::WEBHOOK, 'tender.published');
        $this->provider->method('findActiveForChannelAndDigest')->willReturn([$webhook]);

        $matched = $this->matcher->matchInstant($this->event('tender.published'), NotificationChannelEnum::EMAIL);

        self::assertSame([], $matched);
    }

    public function testFiltersMustMatchPayload(): void
    {
        $filtered = $this->subscription(NotificationChannelEnum::EMAIL, 'tender.published', ['tender_id' => 'tender-42']);
        $this->provider->method('findActiveForChannelAndDigest')->willReturn([$filtered]);

        // Фильтр совпадает — событие доставляется.
        $matched = $this->matcher->matchInstant(
            $this->event('tender.published', ['tender_id' => 'tender-42']),
            NotificationChannelEnum::EMAIL,
        );
        self::assertCount(1, $matched);

        // Фильтр НЕ совпадает (другой тендер) — событие не доставляется.
        $matched = $this->matcher->matchInstant(
            $this->event('tender.published', ['tender_id' => 'tender-7']),
            NotificationChannelEnum::EMAIL,
        );
        self::assertSame([], $matched);
    }

    public function testFiltersMissingKeyRejectsEvent(): void
    {
        $filtered = $this->subscription(NotificationChannelEnum::EMAIL, 'tender.published', ['tender_id' => 'tender-42']);
        $this->provider->method('findActiveForChannelAndDigest')->willReturn([$filtered]);

        $matched = $this->matcher->matchInstant(
            $this->event('tender.published', []),
            NotificationChannelEnum::EMAIL,
        );

        self::assertSame([], $matched);
    }

    public function testMatchDigestUsesDigestSubscriptions(): void
    {
        $digestSub = $this->subscription(NotificationChannelEnum::EMAIL, 'auction.bid', null, digest: true);
        $this->provider->method('findActiveForChannelAndDigest')->willReturn([$digestSub]);

        $matched = $this->matcher->matchDigest($this->event('auction.bid'), NotificationChannelEnum::EMAIL);

        self::assertSame([$digestSub], $matched);
    }

    public function testMatchInstantDoesNotMatchDigestSubscriptions(): void
    {
        $digestSub = $this->subscription(NotificationChannelEnum::EMAIL, 'tender.published', null, digest: true);
        // провайдер для мгновенного матчинга (digest=false) возвращает пусто
        $this->provider->method('findActiveForChannelAndDigest')->willReturn([]);

        $matched = $this->matcher->matchInstant($this->event('tender.published'), NotificationChannelEnum::EMAIL);

        self::assertSame([], $matched);
    }

    public function testMatchDigestFiltersByEventTypeToo(): void
    {
        $digestSub = $this->subscription(NotificationChannelEnum::EMAIL, 'auction.started', null, digest: true);
        $this->provider->method('findActiveForChannelAndDigest')->willReturn([$digestSub]);

        $matched = $this->matcher->matchDigest($this->event('auction.bid'), NotificationChannelEnum::EMAIL);

        self::assertSame([], $matched);
    }
}
