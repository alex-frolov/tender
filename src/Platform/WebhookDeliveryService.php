<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Entity\Enum\WebhookDeliveryStatusEnum;
use App\Platform\Entity\Enum\WebhookStatusEnum;
use App\Platform\Entity\Webhook;
use App\Platform\Entity\WebhookDelivery;
use App\Platform\Exception\WebhookDeliveryException;
use App\Platform\Repository\WebhookDeliveryRepository;
use App\Platform\Service\WebhookMatcher;
use App\Platform\Service\WebhookPayloadBuilder;
use App\Platform\Service\WebhookSigner;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Events\EventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Доставка webhook-событий (WH-1..7, AM-14).
 *
 * Пайплайн: outbox → RabbitMQ (EventMessage) → WebhookDeliveryService::queueDeliveries
 * → WebhookDeliveryMessage (transport `webhooks`) → WebhookDeliveryMessageHandler
 * → HTTP POST с HMAC-подписью (WH-3).
 *
 * queueDeliveries() выполняется в консьюмере доменных событий:
 * - подписки тенанта активные и подходящие (WebhookMatcher: event_type + filters);
 * - на каждую подписку создаётся WebhookDelivery (payload = канонический JSON
 *   тела, подписываемый при отправке); unique (webhook_id, event_id) делает
 *   пересоздание идемпотентным при повторной доставке события (WH-4, at-least-once);
 * - после flush задачи доставки уходят в транспорт.
 *
 * process() выполняется воркером `webhooks`:
 * - HTTP POST с таймаутом (WH-6), X-Signature (WH-3), X-Event-Id (WH-4);
 * - успех (2xx) — статус delivered;
 * - провал — markFailed + ретрай (messenger retry_strategy, backoff WH-5);
 *   после исчерпания лимита попыток — dead (dead-letter) + событие
 *   platform.webhook.failed (алерт) + аудит; недоступный подписчик не
 *   блокирует основной поток (WH-6).
 */
final readonly class WebhookDeliveryService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WebhookDeliveryRepository $deliveries,
        private WebhookMatcher $matcher,
        private WebhookSigner $signer,
        private WebhookPayloadBuilder $payloadBuilder,
        private AuditService $audit,
        private HttpClientInterface $http,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        #[Autowire(param: 'webhook_max_attempts')]
        private int $maxAttempts,
        #[Autowire(param: 'webhook_delivery_timeout')]
        private float $timeout,
    ) {
    }

    /**
     * Создание задач доставки события по подпискам (WH-2, WH-7).
     * Возвращает число созданных/перенаправленных доставок.
     */
    public function queueDeliveries(EventMessage $message): int
    {
        $webhooks = $this->matcher->match($message);
        if ([] === $webhooks) {
            return 0;
        }

        $toDispatch = [];
        foreach ($webhooks as $webhook) {
            $delivery = $this->deliveries->findOneByWebhookAndEvent(
                (string) $webhook->getId(),
                $message->eventId,
            );
            if (null === $delivery) {
                $delivery = new WebhookDelivery(
                    webhook: $webhook,
                    eventId: $this->eventUuid($message->eventId),
                    eventType: $message->eventType,
                    payload: $this->payloadBuilder->build($message),
                );
                $this->em->persist($delivery);
            }
            $toDispatch[] = (string) $delivery->getId();
        }

        $this->em->flush();

        foreach ($toDispatch as $deliveryId) {
            $this->bus->dispatch(new WebhookDeliveryMessage($deliveryId));
        }

        return \count($toDispatch);
    }

    /**
     * Выполнение доставки (WH-2..6). Вызывается обработчиком
     * WebhookDeliveryMessageHandler; ретраи — на уровне транспорта.
     */
    public function process(string $deliveryId): void
    {
        // Каждая попытка читает свежее состояние (в т.ч. attempts после ретрая)
        $this->em->clear();
        $delivery = $this->deliveries->findById($deliveryId);
        if (null === $delivery) {
            $this->logger->warning('Webhook delivery not found', ['delivery_id' => $deliveryId]);

            return;
        }

        // Идемпотентность (WH-4): уже доставленные/мёртвые не трогаем.
        if (\in_array($delivery->getStatus(), [WebhookDeliveryStatusEnum::DELIVERED, WebhookDeliveryStatusEnum::DEAD], true)) {
            return;
        }

        $webhook = $delivery->getWebhook();
        if (WebhookStatusEnum::PAUSED === $webhook->getStatus()) {
            // Подписка приостановлена — событие не отправляем (WH-7); задача
            // считается обработанной без ретрая, запись остаётся pending.
            $this->logger->info('Webhook paused, delivery skipped', [
                'delivery_id' => $deliveryId,
                'webhook_id' => (string) $webhook->getId(),
            ]);

            return;
        }

        $attempt = $delivery->getAttempts() + 1;
        $httpStatus = null;
        try {
            $httpStatus = $this->send($webhook, $delivery);
            $delivery->markDelivered($attempt, $httpStatus);
            $this->em->flush();
            $this->logger->info('Webhook delivered', [
                'delivery_id' => $deliveryId,
                'webhook_id' => (string) $webhook->getId(),
                'event_id' => (string) $delivery->getEventId(),
                'http_status' => $httpStatus,
                'attempt' => $attempt,
            ]);

            return;
        } catch (\Throwable $e) {
            if ($e instanceof TransportExceptionInterface) {
                $message = 'Network error: '.$e->getMessage();
            } else {
                $message = $e->getMessage();
            }
            $this->logger->warning('Webhook delivery failed', [
                'delivery_id' => $deliveryId,
                'webhook_id' => (string) $webhook->getId(),
                'event_id' => (string) $delivery->getEventId(),
                'attempt' => $attempt,
                'http_status' => $httpStatus,
                'error' => $message,
            ]);

            if ($attempt >= $this->maxAttempts) {
                $this->markDead($delivery, $webhook, $attempt, $httpStatus, $message);

                return;
            }

            $this->markFailed($delivery, $attempt, $httpStatus, $message);
            // Ретрай с экспоненциальной задержкой (WH-5) — на уровне транспорта.
            throw new WebhookDeliveryException($message, 0, $e);
        }
    }

    /**
     * HTTP POST к подписчику (WH-2/WH-3/WH-6). Возвращает HTTP-статус;
     * бросает TransportExceptionInterface при сетевой ошибке/таймауте.
     *
     * @throws TransportExceptionInterface
     * @throws \RuntimeException           если статус ответа не 2xx
     */
    private function send(Webhook $webhook, WebhookDelivery $delivery): int
    {
        $body = $delivery->getPayload();
        $response = $this->http->request('POST', $webhook->getUrl(), [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Signature' => $this->signer->signature($body, $webhook->getSecret()),
                'X-Event-Id' => (string) $delivery->getEventId(),
                'User-Agent' => 'Tender-Platform-Webhook/1.0',
            ],
            'body' => $body,
            'timeout' => $this->timeout,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(\sprintf('Webhook endpoint returned HTTP %d', $status));
        }

        return $status;
    }

    /**
     * Провал промежуточной попытки (WH-5): запоминаем ошибку и расчётное время
     * следующей попытки (backoff 1/5/25/… сек); фактическую задержку обеспечивает
     * транспорт (retry_strategy), next_retry_at — информационно для журнала.
     */
    private function markFailed(
        WebhookDelivery $delivery,
        int $attempt,
        ?int $httpStatus,
        string $error,
    ): void {
        $delaySeconds = (int) (1000 * (5 ** ($attempt - 1))) / 1000;
        $nextRetryAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(\sprintf('+%d seconds', max(1, $delaySeconds)));

        $delivery->markFailed($attempt, $httpStatus, $error, $nextRetryAt);
        $this->em->flush();
    }

    /**
     * Dead-letter (WH-5): ретраи исчерпаны. Статус dead + событие
     * platform.webhook.failed (алерт админу тенанта, domain/events.md) + аудит.
     */
    private function markDead(
        WebhookDelivery $delivery,
        Webhook $webhook,
        int $attempt,
        ?int $httpStatus,
        string $error,
    ): void {
        $delivery->markDead($attempt, $httpStatus, $error);
        $this->em->flush();

        $this->em->persist(new OutboxEvent(
            eventType: 'platform.webhook.failed',
            payload: [
                'webhook_id' => (string) $webhook->getId(),
                'delivery_id' => (string) $delivery->getId(),
                'event_type' => $delivery->getEventType(),
                'attempts' => $attempt,
                'last_error' => $error,
                'last_http_status' => $httpStatus,
            ],
            aggregateType: 'webhook_delivery',
            aggregateId: (string) $delivery->getId(),
            tenantId: (string) $webhook->getTenantId(),
        ));
        $this->em->flush();

        $this->audit->record(
            action: 'webhook.delivery_failed',
            entityType: 'webhook_delivery',
            entityId: (string) $delivery->getId(),
            tenantId: (string) $webhook->getTenantId(),
            actorType: 'system',
            after: [
                'webhook_id' => (string) $webhook->getId(),
                'event_type' => $delivery->getEventType(),
                'attempts' => $attempt,
                'status' => WebhookDeliveryStatusEnum::DEAD->value,
            ],
        );
        $this->em->flush();

        $this->logger->error('Webhook delivery dead-lettered', [
            'delivery_id' => (string) $delivery->getId(),
            'webhook_id' => (string) $webhook->getId(),
            'attempts' => $attempt,
            'error' => $error,
        ]);
    }

    /**
     * UUID события из строки (EventMessage.eventId). Невалидный id события —
     * доменная ошибка: событие не может попасть в доставку без event_id (WH-4).
     */
    private function eventUuid(string $eventId): \Symfony\Component\Uid\Uuid
    {
        if (!\Symfony\Component\Uid\Uuid::isValid($eventId)) {
            throw new \LogicException('Event id is not a valid UUID');
        }

        return \Symfony\Component\Uid\Uuid::fromString($eventId);
    }
}
