<?php

declare(strict_types=1);

namespace App\Platform;

use App\Iam\Entity\User;
use App\Platform\Entity\Enum\WebhookStatusEnum;
use App\Platform\Entity\Webhook;
use App\Platform\Entity\WebhookDelivery;
use App\Platform\Exception\WebhookNotFoundException;
use App\Platform\Input\CreateWebhookInput;
use App\Platform\Input\UpdateWebhookInput;
use App\Platform\Repository\WebhookDeliveryRepository;
use App\Platform\Repository\WebhookRepository;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Webhook-подписки (WH-1..7, AM-14, openapi /webhooks).
 *
 * CRUD подписок компании-тенанта (FR-1.5.10: право webhooks.manage — admin):
 * - create — создание подписки с секретом HMAC (WH-3); если secret не передан,
 *   генерируется и отдаётся один раз;
 * - update — смена url/events/status (WH-7); секрет — отдельный /rotate-secret;
 * - delete — удаление подписки (доставки каскадно удаляются);
 * - rotateSecret — ротация секрета (WH-7): новый секрет отдаётся один раз.
 *
 * Tenant-изоляция: подписка принадлежит компании актора; подписка чужой
 * компании невидима (404). Сервис — оркестратор: валидация (uuid, события,
 * статусы) и фиксация (persist + append-only аудит FR-1.8). Доставка событий —
 * WebhookDeliveryService.
 */
final readonly class WebhookService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WebhookRepository $webhooks,
        private WebhookDeliveryRepository $deliveries,
        private AuditService $audit,
    ) {
    }

    /**
     * Создание webhook-подписки (WH-7, POST /webhooks). Тенант — компания
     * актора; secret при пустом значении генерируется (WH-3). Отдаётся
     * подписка вместе с секретом один раз (в ответе создающего запроса).
     *
     * @return array{webhook: Webhook, secret: string}
     *
     * @throws ConflictException   если актор без компании
     * @throws ValidationException если события пусты или статус невалиден
     */
    public function create(User $actor, CreateWebhookInput $input): array
    {
        $tenantId = $this->requireCompany($actor);

        $events = $this->events($input->events);
        $secret = $this->secret($input->secret);

        $webhook = new Webhook(
            tenantId: $tenantId,
            url: $this->url($input->url),
            secret: $secret,
            events: $events,
            filters: $this->filters($input->filters),
            status: $this->status($input->status),
        );

        $this->em->persist($webhook);
        $this->em->flush();

        $this->audit->record(
            action: 'webhook.created',
            entityType: 'webhook',
            entityId: (string) $webhook->getId(),
            tenantId: (string) $tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'url' => $webhook->getUrl(),
                'events' => $events,
                'status' => $webhook->getStatus()->value,
            ],
        );
        $this->em->flush();

        return ['webhook' => $webhook, 'secret' => $secret];
    }

    /**
     * Список подписок компании (GET /webhooks, без секретов).
     *
     * @return list<Webhook>
     */
    public function list(User $actor): array
    {
        return $this->webhooks->listForTenant($this->requireCompany($actor));
    }

    /**
     * Подписка по id (GET /webhooks/{id}, без секрета). Чужая подписка — 404.
     */
    public function get(User $actor, string $webhookId): Webhook
    {
        return $this->resolveOwned($actor, $webhookId);
    }

    /**
     * Изменение подписки (WH-7, PATCH /webhooks/{id}): обновляются только
     * переданные поля (url/events/status). Секрет не меняется.
     *
     * @throws WebhookNotFoundException
     * @throws ValidationException      если события пусты или статус невалиден
     */
    public function update(User $actor, string $webhookId, UpdateWebhookInput $input): Webhook
    {
        $webhook = $this->resolveOwned($actor, $webhookId);

        $before = [
            'url' => $webhook->getUrl(),
            'events' => $webhook->getEvents(),
            'status' => $webhook->getStatus()->value,
        ];

        if (null !== $input->url && '' !== $input->url) {
            $webhook->setUrl($this->url($input->url));
        }
        if (null !== $input->events) {
            $webhook->setEvents($this->events($input->events));
        }
        if (null !== $input->status) {
            $webhook->setStatus($this->status($input->status));
        }

        $this->em->flush();

        $this->audit->record(
            action: 'webhook.updated',
            entityType: 'webhook',
            entityId: (string) $webhook->getId(),
            tenantId: (string) $webhook->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: $before,
            after: [
                'url' => $webhook->getUrl(),
                'events' => $webhook->getEvents(),
                'status' => $webhook->getStatus()->value,
            ],
        );
        $this->em->flush();

        return $webhook;
    }

    /**
     * Удаление подписки (WH-7, DELETE /webhooks/{id}). Доставки каскадно
     * удаляются (FK ON DELETE CASCADE). Возвращает id удалённой подписки.
     */
    public function delete(User $actor, string $webhookId): string
    {
        $webhook = $this->resolveOwned($actor, $webhookId);
        $id = (string) $webhook->getId();
        $tenantId = (string) $webhook->getTenantId();

        $this->em->remove($webhook);
        $this->em->flush();

        $this->audit->record(
            action: 'webhook.deleted',
            entityType: 'webhook',
            entityId: $id,
            tenantId: $tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
        );
        $this->em->flush();

        return $id;
    }

    /**
     * Ротация секрета подписки (WH-7, POST /webhooks/{id}/rotate-secret).
     * Новый секрет отдаётся один раз (в ответе эндпоинта); старый перестаёт
     * действовать немедленно (подпись HMAC считается новым секретом, WH-3).
     *
     * @return array{webhook: Webhook, secret: string}
     */
    public function rotateSecret(User $actor, string $webhookId): array
    {
        $webhook = $this->resolveOwned($actor, $webhookId);
        $secret = bin2hex(random_bytes(32));

        $webhook->rotateSecret($secret);
        $this->em->flush();

        $this->audit->record(
            action: 'webhook.secret_rotated',
            entityType: 'webhook',
            entityId: (string) $webhook->getId(),
            tenantId: (string) $webhook->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
        );
        $this->em->flush();

        return ['webhook' => $webhook, 'secret' => $secret];
    }

    /**
     * Страница журнала доставок подписки (WH-2..6, GET /webhooks/{id}/deliveries),
     * новые сверху. Подписка проверяется на принадлежность актору (404 для чужих);
     * keyset-срез по (created_at, id) DESC делает БД, $limit вызывающий передаёт
     * как limit+1 (лишняя строка = есть следующая страница).
     *
     * @return list<WebhookDelivery>
     */
    public function listDeliveries(
        User $actor,
        string $webhookId,
        ?\DateTimeImmutable $cursorCreatedAt,
        ?Uuid $cursorId,
        int $limit,
    ): array {
        $this->resolveOwned($actor, $webhookId);

        return $this->deliveries->listForWebhook($webhookId, $cursorCreatedAt, $cursorId, $limit);
    }

    /**
     * @throws ConflictException если актор без компании
     */
    private function requireCompany(User $actor): Uuid
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $companyId;
    }

    /**
     * @throws WebhookNotFoundException если подписка не найдена или чужая
     */
    private function resolveOwned(User $actor, string $webhookId): Webhook
    {
        $tenantId = $this->requireCompany($actor);
        $webhook = $this->webhooks->findById($webhookId);
        if (null === $webhook || !$webhook->getTenantId()->equals($tenantId)) {
            throw new WebhookNotFoundException('Webhook not found');
        }

        return $webhook;
    }

    /**
     * URL подписчика: http/https.
     *
     * @throws ValidationException
     */
    private function url(string $value): string
    {
        $url = trim($value);
        if ('' === $url || !filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new ValidationException('url must be a valid URL');
        }
        $scheme = (string) parse_url($url, \PHP_URL_SCHEME);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException('url must use http or https scheme');
        }

        return $url;
    }

    /**
     * Секрет HMAC-подписи (WH-3): переданный (16–128 симв.) или сгенерированный.
     *
     * @throws ValidationException
     */
    private function secret(?string $value): string
    {
        if (null === $value || '' === $value) {
            return bin2hex(random_bytes(32));
        }
        $length = \strlen($value);
        if ($length < 16 || $length > 128) {
            throw new ValidationException('secret must be between 16 and 128 characters');
        }

        return $value;
    }

    /**
     * События подписки (WH-1): непустой список, дедупликация.
     *
     * @param list<string>|null $value
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function events(?array $value): array
    {
        if (null === $value || [] === $value) {
            throw new ValidationException('events must not be empty');
        }

        $events = [];
        foreach ($value as $event) {
            if (!\is_string($event) || !preg_match('/^[a-z]+\.[a-z_]+$/', $event)) {
                throw new ValidationException(\sprintf('invalid event type "%s"', \is_string($event) ? $event : '?'));
            }
            $events[$event] = $event;
        }

        return array_values($events);
    }

    /**
     * Фильтры payload (WH-7): произвольный JSON-объект.
     *
     * @param array<string, mixed>|null $value
     *
     * @return array<string, mixed>|null
     */
    private function filters(?array $value): ?array
    {
        if (null === $value || [] === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @throws ValidationException
     */
    private function status(?string $value): WebhookStatusEnum
    {
        if (null === $value || '' === $value) {
            return WebhookStatusEnum::ACTIVE;
        }

        return WebhookStatusEnum::tryFrom($value)
            ?? throw new ValidationException('invalid status');
    }
}
