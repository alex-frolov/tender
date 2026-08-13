<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Tender\Entity\Enum\CancellationReasonEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Tender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Транзакционный «хвост» мутаций тендера (внутренний support-класс модуля
 * Tender): persist/flush и append-only аудит (FR-1.8) для
 * create/update/publish/withdraw/cancel/rate, плюс генерация номера тендера
 * (T-NNNNNN). Вызывается из TenderService после
 * валидации и применения workflow — сервис остаётся оркестратором, без прямой
 * работы с EntityManager/AuditService.
 */
final readonly class TenderTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
    ) {
    }

    /**
     * Генерация номера тендера: T-NNNNNN по счётчику существующих.
     */
    public function nextNumber(): string
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Tender::class, 't')
            ->getQuery()
            ->getSingleScalarResult();

        return 'T-'.str_pad((string) ($count + 1), 6, '0', \STR_PAD_LEFT);
    }

    /**
     * Создание тендера: persist + flush + аудит tender.created (FR-1.1.1, FR-1.8).
     * Лоты уже добавлены в тендер (addLot) — cascade persist одной порцией.
     */
    public function commitCreated(Tender $tender, User $actor, Uuid $companyId, ?string $ip): void
    {
        $this->em->persist($tender);
        $this->em->flush();

        $this->audit->record(
            action: 'tender.created',
            entityType: 'tender',
            entityId: (string) $tender->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: ['number' => $tender->getNumber(), 'status' => TenderStatusEnum::DRAFT->value, 'lot_count' => $tender->lotCount()],
            ip: $ip,
        );
    }

    /**
     * Правка тендера: flush + аудит tender.updated (FR-1.1.1, FR-1.8).
     * change_reason пишется в аудит (в тендере не хранится).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function commitUpdated(
        Tender $tender,
        array $before,
        array $after,
        ?string $changeReason,
        User $actor,
        Uuid $companyId,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'tender.updated',
            entityType: 'tender',
            entityId: (string) $tender->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: $before,
            after: array_merge($after, ['change_reason' => $changeReason]),
            ip: $ip,
        );
    }

    /**
     * Публикация тендера: flush + аудит tender.published (FR-1.1.4, FR-1.8).
     * Таймлайн (сроки) уже рассчитан и установлен в тендере; планирование
     * авто-переходов (TimelineMessage) выполняет сервис до вызова.
     *
     * @param array<string, string> $timeline
     */
    public function commitPublished(
        Tender $tender,
        TenderStatusEnum $before,
        array $timeline,
        User $actor,
        Uuid $companyId,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'tender.published',
            entityType: 'tender',
            entityId: (string) $tender->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value],
            after: ['status' => $tender->getStatus()->value, 'timeline' => $timeline],
            ip: $ip,
        );
    }

    /**
     * Отзыв публикации: flush + аудит tender.withdrawn (B3, FR-1.1.3, FR-1.8).
     * Причина отзыва — свободный текст, пишется в аудит.
     */
    public function commitWithdrawn(
        Tender $tender,
        TenderStatusEnum $before,
        string $reason,
        User $actor,
        Uuid $companyId,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'tender.withdrawn',
            entityType: 'tender',
            entityId: (string) $tender->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value],
            after: ['status' => $tender->getStatus()->value, 'reason' => $reason],
            ip: $ip,
        );
    }

    /**
     * Отмена тендера: flush + аудит tender.cancelled (FR-1.1.8, FR-1.8).
     * Причина (код + свободный текст) уже сохранена в тендере (cancel()).
     */
    public function commitCancelled(
        Tender $tender,
        TenderStatusEnum $before,
        CancellationReasonEnum $code,
        User $actor,
        Uuid $companyId,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'tender.cancelled',
            entityType: 'tender',
            entityId: (string) $tender->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value],
            after: [
                'status' => $tender->getStatus()->value,
                'cancellation_reason_code' => $code->value,
                'cancellation_reason_text' => $tender->getCancellationReasonText(),
            ],
            ip: $ip,
        );
    }

    /**
     * Оценка исполнения: flush + аудит tender.rated (FR-1.1.10, FR-1.8).
     */
    public function commitRated(
        Tender $tender,
        ?int $before,
        ?int $rating,
        User $actor,
        Uuid $companyId,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'tender.rated',
            entityType: 'tender',
            entityId: (string) $tender->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['execution_rating' => $before],
            after: ['execution_rating' => $rating],
            ip: $ip,
        );
    }
}
