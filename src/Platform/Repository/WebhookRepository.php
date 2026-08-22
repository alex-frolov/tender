<?php

declare(strict_types=1);

namespace App\Platform\Repository;

use App\Platform\Entity\Enum\WebhookStatusEnum;
use App\Platform\Entity\Webhook;
use App\Platform\Exception\WebhookNotFoundException;
use App\Platform\Service\ActiveWebhooksProviderInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Webhook-подписки (WH-1..7).
 *
 * - findById(): lookup по id БЕЗ tenant-фильтра — принадлежность компании
 *   проверяет WebhookService (404 для чужих);
 * - findOwnedOrFail(): подписка компании актора или 404 — точка резолва для
 *   контроллера entity-bound update формы (PATCH /webhooks/{id}), которая
 *   получает сущность ДО сабмита формы (см. AGENTS.md);
 * - listForTenant(): подписки компании (GET /webhooks);
 * - findActiveForTenant(): активные подписки тенанта — кандидаты на доставку
 *   события (фильтр по типам событий и payload-фильтрам — в WebhookMatcher).
 *
 * @extends ServiceEntityRepository<Webhook>
 */
final class WebhookRepository extends ServiceEntityRepository implements ActiveWebhooksProviderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webhook::class);
    }

    public function findById(string $webhookId): ?Webhook
    {
        if (!Uuid::isValid($webhookId)) {
            return null;
        }

        /** @var Webhook|null $webhook */
        $webhook = $this->findOneBy(['id' => Uuid::fromString($webhookId)]);

        return $webhook;
    }

    /**
     * Подписка компании актора по id или 404.
     *
     * Чужая подписка неотличима от несуществующей: наличие webhook'а в другой
     * компании — не то, что стоит подтверждать по id.
     *
     * @throws WebhookNotFoundException если id невалиден, подписка не найдена или чужая
     */
    public function findOwnedOrFail(string $webhookId, Uuid $tenantId): Webhook
    {
        $webhook = $this->findById($webhookId);
        if (null === $webhook || !$webhook->getTenantId()->equals($tenantId)) {
            throw new WebhookNotFoundException('Webhook not found');
        }

        return $webhook;
    }

    /**
     * @return list<Webhook>
     */
    public function listForTenant(Uuid $tenantId): array
    {
        /** @var list<Webhook> $result */
        $result = $this->createQueryBuilder('w')
            ->where('w.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Активные подписки тенанта (статус active) — кандидаты на доставку события.
     * Соответствие по типам событий и фильтрам payload проверяет WebhookMatcher.
     *
     * @return list<Webhook>
     */
    public function findActiveForTenant(string $tenantId): array
    {
        /** @var list<Webhook> $result */
        $result = $this->createQueryBuilder('w')
            ->where('w.tenantId = :tenant')
            ->andWhere('w.status = :status')
            ->setParameter('tenant', $tenantId)
            ->setParameter('status', WebhookStatusEnum::ACTIVE->value)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
