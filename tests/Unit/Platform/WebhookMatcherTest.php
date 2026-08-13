<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Platform\Entity\Enum\WebhookStatusEnum;
use App\Platform\Entity\Webhook;
use App\Platform\Service\ActiveWebhooksProviderInterface;
use App\Platform\Service\WebhookMatcher;
use App\Shared\Events\EventMessage;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Матчинг событий на webhook-подписки (WH-1..7).
 *
 * - событие без tenant_id не попадает в доставку;
 * - активная подписка с подходящим event_type матчится;
 * - подписка с другим event_type / paused — не матчится;
 * - фильтры (WH-7): совпадение по полям payload и несовпадение отсекается.
 */
final class WebhookMatcherTest extends TestCase
{
    private Uuid $tenant;
    private ActiveWebhooksProviderInterface&Stub $provider;
    private WebhookMatcher $matcher;

    protected function setUp(): void
    {
        $this->tenant = Uuid::v4();
        $this->provider = self::createStub(ActiveWebhooksProviderInterface::class);
        $this->matcher = new WebhookMatcher($this->provider);
    }

    /**
     * @param list<string>              $events
     * @param array<string, mixed>|null $filters
     */
    private function webhook(
        array $events,
        WebhookStatusEnum $status = WebhookStatusEnum::ACTIVE,
        ?array $filters = null,
    ): Webhook {
        return new Webhook(
            tenantId: $this->tenant,
            url: 'https://example.com/hook',
            secret: '0123456789abcdef',
            events: $events,
            filters: $filters,
            status: $status,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(?string $tenantId, string $eventType, array $payload = []): EventMessage
    {
        return EventMessage::create($eventType, $tenantId, 'tender', 'tender-1', $payload);
    }

    public function testEventWithoutTenantIsSkipped(): void
    {
        $mock = self::createMock(ActiveWebhooksProviderInterface::class);
        $mock->expects(self::never())->method('findActiveForTenant');
        $matcher = new WebhookMatcher($mock);

        self::assertSame([], $matcher->match($this->event(null, 'tender.published')));
    }

    public function testMatchesActiveSubscriptionWithEventType(): void
    {
        $hook = $this->webhook(['tender.published']);
        $this->provider->method('findActiveForTenant')->willReturn([$hook]);

        $matched = $this->matcher->match($this->event((string) $this->tenant, 'tender.published'));

        self::assertSame([$hook], $matched);
    }

    public function testSkipsSubscriptionWithDifferentEventType(): void
    {
        $hook = $this->webhook(['auction.started']);
        $this->provider->method('findActiveForTenant')->willReturn([$hook]);

        self::assertSame([], $this->matcher->match($this->event((string) $this->tenant, 'tender.published')));
    }

    public function testSkipsPausedSubscription(): void
    {
        $hook = $this->webhook(['tender.published'], WebhookStatusEnum::PAUSED);
        $this->provider->method('findActiveForTenant')->willReturn([$hook]);

        self::assertSame([], $this->matcher->match($this->event((string) $this->tenant, 'tender.published')));
    }

    public function testFiltersMustMatchPayload(): void
    {
        $hook = $this->webhook(['tender.published'], filters: ['tender_id' => 'tender-42']);
        $this->provider->method('findActiveForTenant')->willReturn([$hook]);

        $matched = $this->matcher->match($this->event((string) $this->tenant, 'tender.published', ['tender_id' => 'tender-42']));
        self::assertSame([$hook], $matched);

        self::assertSame([], $this->matcher->match(
            $this->event((string) $this->tenant, 'tender.published', ['tender_id' => 'tender-7']),
        ));
    }

    public function testFiltersMissingKeyRejectsEvent(): void
    {
        $hook = $this->webhook(['tender.published'], filters: ['tender_id' => 'tender-42']);
        $this->provider->method('findActiveForTenant')->willReturn([$hook]);

        self::assertSame([], $this->matcher->match(
            $this->event((string) $this->tenant, 'tender.published', ['number' => 'T-1']),
        ));
    }

    public function testMultipleSubscriptionsFiltered(): void
    {
        $matching = $this->webhook(['tender.published']);
        $other = $this->webhook(['auction.bid']);
        $this->provider->method('findActiveForTenant')->willReturn([$matching, $other]);

        $matched = $this->matcher->match($this->event((string) $this->tenant, 'tender.published'));

        self::assertSame([$matching], $matched);
    }
}
