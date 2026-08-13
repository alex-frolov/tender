<?php

declare(strict_types=1);

namespace App\Bid\Service;

use App\Bid\Entity\Bid;
use App\Bid\Entity\Enum\BidDecisionEnum;
use App\Bid\Entity\Enum\BidStatusEnum;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Транзакционный «хвост» мутаций заявки (внутренний support-класс модуля Bid):
 * persist/flush + append-only аудит (FR-1.8) и outbox-события bid.* для
 * submit/replace/withdraw/qualify. Вызывается из BidService после валидации
 * и шифрования содержимого (BidPayloadCipher) — сервис остаётся оркестратором.
 */
final readonly class BidTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
    ) {
    }

    /**
     * Подача заявки (FR-1.2.1): persist + flush + аудит bid.submitted (FR-1.8).
     * Содержимое уже зашифровано сервисом (encrypted_payload, FR-1.2.2).
     */
    public function commitSubmitted(
        Bid $bid,
        User $actor,
        Tender $tender,
        ?Lot $lot,
        Uuid $supplierId,
        ?string $ip,
    ): void {
        $this->em->persist($bid);
        $this->em->flush();

        $this->audit->record(
            action: 'bid.submitted',
            entityType: 'bid',
            entityId: (string) $bid->getId(),
            tenantId: (string) $tender->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'tender_id' => (string) $tender->getId(),
                'lot_id' => null !== $lot ? (string) $lot->getId() : null,
                'supplier_id' => (string) $supplierId,
                'status' => $bid->getStatus()->value,
                'payload_encrypted' => true,
            ],
            ip: $ip,
        );
    }

    /**
     * Замена заявки (FR-1.2.5): flush + аудит bid.replaced (FR-1.8).
     * Содержимое уже зашифровано сервисом; одна заявка на лот сохраняется
     * (новая строка не создаётся).
     */
    public function commitReplaced(
        Bid $bid,
        User $actor,
        ?Lot $lot,
        BidStatusEnum $before,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'bid.replaced',
            entityType: 'bid',
            entityId: (string) $bid->getId(),
            tenantId: (string) $bid->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value, 'lot_id' => null !== $bid->getLotId() ? (string) $bid->getLotId() : null],
            after: [
                'tender_id' => (string) $bid->getTenderId(),
                'lot_id' => null !== $lot ? (string) $lot->getId() : null,
                'supplier_id' => (string) $bid->getSupplierId(),
                'status' => $bid->getStatus()->value,
                'payload_encrypted' => true,
            ],
            ip: $ip,
        );
    }

    /**
     * Отзыв заявки (FR-1.2.5, AM-4): flush + аудит bid.withdrawn (FR-1.8).
     * Причина сохранена сервисом в decision_reason.
     */
    public function commitWithdrawn(
        Bid $bid,
        User $actor,
        BidStatusEnum $before,
        string $reason,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'bid.withdrawn',
            entityType: 'bid',
            entityId: (string) $bid->getId(),
            tenantId: (string) $bid->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value],
            after: ['status' => $bid->getStatus()->value, 'reason' => $reason],
            ip: $ip,
        );
    }

    /**
     * Допуск/отклонение заявки (FR-1.2.4, UC-05, AM-4): flush + аудит
     * bid.qualified + outbox bid.qualified (domain/events.md). Статус и причина
     * уже установлены сервисом; уведомление об отклонении — до вызова.
     */
    public function commitQualified(
        Bid $bid,
        User $actor,
        BidStatusEnum $before,
        BidDecisionEnum $decision,
        string $reason,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'bid.qualified',
            entityType: 'bid',
            entityId: (string) $bid->getId(),
            tenantId: (string) $bid->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value],
            after: [
                'status' => $bid->getStatus()->value,
                'decision' => $decision->value,
                'reason' => $reason,
                'evaluated_at' => $bid->getEvaluatedAt()?->format('Y-m-d\TH:i:s\Z'),
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'bid.qualified',
            payload: [
                'bid_id' => (string) $bid->getId(),
                'tender_id' => (string) $bid->getTenderId(),
                'supplier_id' => (string) $bid->getSupplierId(),
                'decision' => $decision->value,
                'reason' => $reason,
            ],
            aggregateType: 'bid',
            aggregateId: (string) $bid->getId(),
            tenantId: (string) $bid->getTenantId(),
        ));
        $this->em->flush();
    }
}
