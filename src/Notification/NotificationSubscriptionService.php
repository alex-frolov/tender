<?php

declare(strict_types=1);

namespace App\Notification;

use App\Iam\Entity\User;
use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\Entity\NotificationSubscription;
use App\Notification\Exception\NotificationSubscriptionNotFoundException;
use App\Notification\Input\CreateNotificationSubscriptionInput;
use App\Notification\Repository\NotificationSubscriptionRepository;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Подписки пользователя на уведомления (FR-1.6, openapi /notifications/subscriptions).
 *
 * Self-service подписки (AM-11): каждый пользователь управляет своими подписками
 * (my subscriptions). Подписка привязывается к пользователю (user_id) и его
 * компании-тенанту (tenant_id). Другие пользователи (и их компании) чужую
 * подписку не видят — 404 (как webhook-подписки, tenant-изоляция на уровне актора).
 *
 * - create — создание подписки (канал/события/фильтры/digest, FR-1.6.1..1.6.3);
 * - toggle — включение/выключение подписки (active, AM-11);
 * - delete — удаление подписки.
 *
 * Сервис — оркестратор: валидация (канал, события, принадлежность) и фиксация
 * (persist + append-only аудит FR-1.8). Доставка — NotificationDeliveryService.
 */
final readonly class NotificationSubscriptionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationSubscriptionRepository $subscriptions,
        private AuditService $audit,
    ) {
    }

    /**
     * Создание подписки (FR-1.6, POST /notifications/subscriptions).
     * Тенант подписки — компания актора; без компании (platform_admin вне
     * тенанта) подписка недоступна.
     *
     * @throws ConflictException   если актор без компании
     * @throws ValidationException если события пусты или канал невалиден
     */
    public function create(User $actor, CreateNotificationSubscriptionInput $input): NotificationSubscription
    {
        $tenantId = $this->requireCompany($actor);

        $subscription = new NotificationSubscription(
            userId: $actor->getId(),
            tenantId: $tenantId,
            channel: $this->channel($input->channel),
            events: $this->events($input->events),
            filters: $this->filters($input->filters),
            digest: $input->digest ?? false,
        );

        $this->em->persist($subscription);
        $this->em->flush();

        $this->audit->record(
            action: 'notification.subscription.created',
            entityType: 'notification_subscription',
            entityId: (string) $subscription->getId(),
            tenantId: (string) $tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'channel' => $subscription->getChannel()->value,
                'events' => $subscription->getEvents(),
                'digest' => $subscription->isDigest(),
            ],
        );
        $this->em->flush();

        return $subscription;
    }

    /**
     * Список подписок пользователя (GET /notifications/subscriptions).
     *
     * @return list<NotificationSubscription>
     */
    public function list(User $actor): array
    {
        return $this->subscriptions->listForUser($actor->getId());
    }

    /**
     * Включение/выключение подписки (AM-11, POST /notifications/subscriptions/{id}/toggle).
     * Возвращает подписку с обновлённым active.
     *
     * @throws NotificationSubscriptionNotFoundException
     */
    public function toggle(User $actor, string $subscriptionId): NotificationSubscription
    {
        $subscription = $this->resolveOwned($actor, $subscriptionId);
        $before = $subscription->isActive();

        $subscription->setActive(!$before);
        $this->em->flush();

        $this->audit->record(
            action: 'notification.subscription.toggled',
            entityType: 'notification_subscription',
            entityId: (string) $subscription->getId(),
            tenantId: (string) $subscription->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['active' => $before],
            after: ['active' => $subscription->isActive()],
        );
        $this->em->flush();

        return $subscription;
    }

    /**
     * Удаление подписки (DELETE /notifications/subscriptions?subscriptionId=...).
     * Возвращает id удалённой подписки.
     *
     * @throws NotificationSubscriptionNotFoundException
     */
    public function delete(User $actor, string $subscriptionId): string
    {
        $subscription = $this->resolveOwned($actor, $subscriptionId);
        $id = (string) $subscription->getId();
        $tenantId = (string) $subscription->getTenantId();

        $this->em->remove($subscription);
        $this->em->flush();

        $this->audit->record(
            action: 'notification.subscription.deleted',
            entityType: 'notification_subscription',
            entityId: $id,
            tenantId: $tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
        );
        $this->em->flush();

        return $id;
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
     * @throws NotificationSubscriptionNotFoundException если подписка не найдена
     *                                                   или принадлежит другому пользователю
     */
    private function resolveOwned(User $actor, string $subscriptionId): NotificationSubscription
    {
        $subscription = $this->subscriptions->findById($subscriptionId);
        if (null === $subscription || !$subscription->getUserId()->equals($actor->getId())) {
            throw new NotificationSubscriptionNotFoundException('Notification subscription not found');
        }

        return $subscription;
    }

    /**
     * @throws ValidationException
     */
    private function channel(string $value): NotificationChannelEnum
    {
        return NotificationChannelEnum::tryFrom($value)
            ?? throw new ValidationException('invalid channel');
    }

    /**
     * События подписки (FR-1.6.2): непустой список, дедупликация.
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
     * Фильтры payload (FR-1.6.3): произвольный JSON-объект.
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
}
