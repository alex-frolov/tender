<?php

declare(strict_types=1);

namespace App\Contract\Service;

use App\Contract\Entity\Contract;
use App\Contract\Entity\ContractTender;
use App\Contract\Entity\Enum\ContractStatusEnum;
use App\Contract\Entity\Enum\ContractStatusTransition;
use App\Contract\Entity\Enum\ContractTenderStatusEnum;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Exception\StateTransitionException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Транзакционный «хвост» мутаций договора (внутренний support-класс модуля
 * Contract): persist/flush, append-only аудит (FR-1.8) и outbox-события
 * contract.*, workflow-переходы жизненного цикла (state_machine.contract)
 * и генерация номера договора (C-NNNNNN). Вызывается из ContractService
 * после валидации (стороны, workflow-guards) — сервис остаётся оркестратором.
 *
 * Переходы статуса — только через symfony/workflow (state_machine.contract);
 * каждая мутация пишет append-only аудит (FR-1.8) и outbox-событие contract.*.
 */
final readonly class ContractTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        #[Autowire(service: 'state_machine.contract')]
        private WorkflowInterface $contractWorkflow,
    ) {
    }

    /**
     * Генерация номера договора: C-NNNNNN по счётчику существующих.
     */
    public function nextNumber(): string
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Contract::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();

        return 'C-'.str_pad((string) ($count + 1), 6, '0', \STR_PAD_LEFT);
    }

    /**
     * Создание договора: flush + аудит contract.created + outbox contract.created
     * (FR-1.4.3/1.4.8, UC-08/UC-08d, FR-1.8). Договор и привязка тендера
     * (source=tender, contract_tenders) уже persist-нуты сервисом (bindTenderInternal)
     * — здесь фиксация append-only журнала и события одной порцией.
     */
    public function commitCreated(
        Contract $contract,
        string $typeCode,
        string $source,
        string $scope,
        Uuid $supplierId,
        User $actor,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'contract.created',
            entityType: 'contract',
            entityId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'number' => $contract->getNumber(),
                'status' => ContractStatusEnum::DRAFT->value,
                'source' => $source,
                'scope' => $scope,
                'supplier_id' => (string) $supplierId,
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'contract.created',
            payload: [
                'contract_id' => (string) $contract->getId(),
                'type_code' => $typeCode,
                'source' => $source,
                'supplier_id' => (string) $supplierId,
                'customer_id' => (string) $contract->getCustomerId(),
                'scope' => $scope,
            ],
            aggregateType: 'contract',
            aggregateId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
        ));
        $this->em->flush();
    }

    /**
     * Привязка тендера к договору: flush + аудит contract.tender_bound + outbox
     * contract.tender_bound (FR-1.4.6, FR-1.8). ContractTender уже создан
     * сервисом (bindTenderInternal) — здесь только фиксация.
     */
    public function commitBoundTender(
        Contract $contract,
        ContractTender $tender,
        Uuid $tenderId,
        ?int $priceNetMinor,
        User $actor,
        ?string $ip,
    ): void {
        $this->em->flush();

        $this->audit->record(
            action: 'contract.tender_bound',
            entityType: 'contract',
            entityId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'contract_tender_id' => (string) $tender->getId(),
                'tender_id' => (string) $tenderId,
                'price_net_minor' => $priceNetMinor,
                'status' => ContractTenderStatusEnum::PENDING->value,
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'contract.tender_bound',
            payload: [
                'contract_id' => (string) $contract->getId(),
                'tender_id' => (string) $tenderId,
                'price_minor' => $priceNetMinor,
            ],
            aggregateType: 'contract',
            aggregateId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
        ));
        $this->em->flush();
    }

    /**
     * Отправка на подписание (C1, draft → pending_signature): workflow-guard,
     * переход, flush + аудит contract.sent_for_signature + outbox
     * contract.pending_signature. Инициирует заказчик (customer) — проверка
     * актора в сервисе.
     *
     * @throws StateTransitionException если договор не в статусе draft
     */
    public function applySendForSignature(Contract $contract, User $actor, ?string $ip): void
    {
        $transition = ContractStatusTransition::SEND_FOR_SIGNATURE->value;
        if (!$this->contractWorkflow->can($contract, $transition)) {
            throw new StateTransitionException('Only draft contracts can be sent for signature');
        }

        $before = $contract->getStatus();
        $this->contractWorkflow->apply($contract, $transition);
        $this->em->flush();

        $this->audit->record(
            action: 'contract.sent_for_signature',
            entityType: 'contract',
            entityId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value],
            after: ['status' => $contract->getStatus()->value],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'contract.pending_signature',
            payload: [
                'contract_id' => (string) $contract->getId(),
                'parties' => ['customer' => (string) $contract->getCustomerId(), 'supplier' => (string) $contract->getSupplierId()],
            ],
            aggregateType: 'contract',
            aggregateId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
        ));
        $this->em->flush();
    }

    /**
     * Подписание договора одной из сторон (C2, ЭП-заглушка, FR-1.4.3).
     * При подписях ОБЕИХ сторон workflow-переход sign (guard по флагам) переводит
     * договор в signed, фиксируется signed_at и публикуется contract.signed;
     * при одной подписи — только аудит contract.party_signed (без события).
     * signParty и флаги сторон — в сервисе (знание «кто подписал»).
     *
     * @param string $party 'customer'|'supplier'
     */
    public function commitSigned(
        Contract $contract,
        string $party,
        User $actor,
        ?string $ip,
    ): void {
        $before = $contract->getStatus();
        $signed = false;

        if ($contract->isSignedByCustomer() && $contract->isSignedBySupplier()) {
            $this->contractWorkflow->apply($contract, ContractStatusTransition::SIGN->value);
            $contract->markSignedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->em->flush();
            $signed = true;
        }

        $after = $signed
            ? ['status' => $contract->getStatus()->value, 'signed_at' => $contract->getSignedAt()?->format('Y-m-d\TH:i:s\Z')]
            : ['status' => $contract->getStatus()->value, 'party' => $party];

        $this->audit->record(
            action: $signed ? 'contract.signed' : 'contract.party_signed',
            entityType: 'contract',
            entityId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value],
            after: $after,
            ip: $ip,
        );

        if ($signed) {
            $this->em->persist(new OutboxEvent(
                eventType: 'contract.signed',
                payload: [
                    'contract_id' => (string) $contract->getId(),
                    'signed_at' => $contract->getSignedAt()?->format('Y-m-d\TH:i:s\Z'),
                    'price_net_minor' => $contract->getPriceNetMinor(),
                    'vat_rate' => $contract->getVatRateBps() / 100,
                ],
                aggregateType: 'contract',
                aggregateId: (string) $contract->getId(),
                tenantId: (string) $contract->getTenantId(),
            ));
            $this->em->flush();
        }
    }

    /**
     * Регистрация договора в учёте (C6, signed → registered): workflow-guard,
     * переход, фиксация registered_at, flush + аудит contract.registered + outbox
     * contract.registered. Вызывается заказчиком (проверка в сервисе).
     *
     * @throws StateTransitionException если договор не в статусе signed
     */
    public function applyRegister(Contract $contract, User $actor, ?string $ip): void
    {
        $transition = ContractStatusTransition::REGISTER->value;
        if (!$this->contractWorkflow->can($contract, $transition)) {
            throw new StateTransitionException('Only signed contracts can be registered');
        }

        $before = $contract->getStatus();
        $this->contractWorkflow->apply($contract, $transition);
        $contract->markRegisteredAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->flush();

        $this->audit->record(
            action: 'contract.registered',
            entityType: 'contract',
            entityId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value],
            after: ['status' => $contract->getStatus()->value, 'registered_at' => $contract->getRegisteredAt()?->format('Y-m-d\TH:i:s\Z')],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'contract.registered',
            payload: [
                'contract_id' => (string) $contract->getId(),
                'registered_at' => $contract->getRegisteredAt()?->format('Y-m-d\TH:i:s\Z'),
            ],
            aggregateType: 'contract',
            aggregateId: (string) $contract->getId(),
            tenantId: (string) $contract->getTenantId(),
        ));
        $this->em->flush();
    }
}
