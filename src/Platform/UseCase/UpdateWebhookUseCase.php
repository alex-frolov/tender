<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Entity\Webhook;
use App\Platform\Presenter\WebhookPresenter;
use App\Shared\Audit\AuditService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменение webhook-подписки (WH-7, PATCH /webhooks/{id}).
 *
 * Доступ проверяется ДО вызова (WebhookVoter::MANAGE), подписка — уже
 * резолвленная сущность из контроллера (WebhookRepository::findOwnedOrFail,
 * tenant-изоляция), а изменения применены entity-bound формой WebhookUpdateType
 * (clearMissing: false, см. AGENTS.md). UseCase фиксирует изменения (flush),
 * пишет аудит и возвращает презентацию (без секрета).
 */
final readonly class UpdateWebhookUseCase implements PlatformUseCase
{
    public function __construct(
        private EntityManagerInterface $em,
        private WebhookPresenter $presenter,
        private AuditService $audit,
    ) {
    }

    /**
     * @param Webhook $before снапшот подписки ДО мутации формой (для аудита before/after)
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, Webhook $webhook, Webhook $before): array
    {
        $this->em->flush();

        $this->audit->record(
            action: 'webhook.updated',
            entityType: 'webhook',
            entityId: (string) $webhook->getId(),
            tenantId: (string) $webhook->getTenantId(),
            actorType: 'user',
            actorId: (string) $user->getId(),
            before: [
                'url' => $before->getUrl(),
                'events' => $before->getEvents(),
                'status' => $before->getStatus()->value,
            ],
            after: [
                'url' => $webhook->getUrl(),
                'events' => $webhook->getEvents(),
                'status' => $webhook->getStatus()->value,
            ],
        );
        $this->em->flush();

        return $this->presenter->single($webhook);
    }
}
