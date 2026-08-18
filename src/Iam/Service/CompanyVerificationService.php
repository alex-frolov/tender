<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Entity\Enum\CompanyStatusTransition;
use App\Iam\Entity\User;
use App\Iam\Exception\CompanyNotFoundException;
use App\Iam\Repository\CompanyRepository;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\StateTransitionException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Модерация компаний суперадмином (FR-1.5.7).
 *
 * Только пользователь с ролью platform_admin подтверждает/отклоняет/
 * приостанавливает компанию. Переходы по статусам выполняются только
 * через workflow `company_verification` (config/packages/workflow.yaml,
 * domain/company-state-machine.md), а не прямым присваиванием статуса.
 * Допустимые переходы:
 *  - approve: pending/suspended → active;
 *  - reject:  pending/suspended → rejected;
 *  - suspend: active → suspended.
 * Каждая мутация пишет append-only запись в аудит.
 *
 * Валидация входных данных (action, reason) и разбор companyId происходят ЗДЕСЬ,
 * а не в контроллере. Ошибки бросаются как ApiException (ValidationException/
 * CompanyNotFoundException/StateTransitionException) и единообразно превращаются
 * в JSON-ответ подписчиком JsonApiExceptionSubscriber — контроллер остаётся тонким.
 */
final readonly class CompanyVerificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CompanyRepository $companyRepository,
        private AuditService $audit,
        #[Autowire(service: 'state_machine.company_verification')]
        private WorkflowInterface $companyWorkflow,
    ) {
    }

    /**
     * Точка входа для контроллера: разбор action и применения перехода.
     *
     * @throws ValidationException      если action неизвестен или для reject не указана причина
     * @throws CompanyNotFoundException если компания не найдена
     * @throws StateTransitionException если переход недопустим для текущего статуса
     */
    public function verify(User $actor, string $companyId, string $action, string $reason, ?string $ip = null): Company
    {
        $id = $this->resolveCompanyId($companyId);

        return match ($action) {
            'approve' => $this->approve($id, $actor, $ip),
            'reject' => $this->reject($id, $actor, $reason, $ip),
            'suspend' => $this->suspend($id, $actor, $ip),
            default => throw new ValidationException('Invalid action'),
        };
    }

    /**
     * Подтвердить компанию: pending/suspended → active.
     *
     * @throws StateTransitionException если переход недопустим
     */
    public function approve(Uuid $companyId, User $actor, ?string $ip = null): Company
    {
        return $this->apply($companyId, $actor, CompanyStatusTransition::APPROVE->value, 'company.approved', $ip);
    }

    /**
     * Отклонить компанию: pending/suspended → rejected.
     *
     * @throws ValidationException      если не указана причина
     * @throws StateTransitionException если переход недопустим
     */
    public function reject(Uuid $companyId, User $actor, string $reason, ?string $ip = null): Company
    {
        if ('' === trim($reason)) {
            throw new ValidationException('reason is required for reject');
        }

        return $this->apply($companyId, $actor, CompanyStatusTransition::REJECT->value, 'company.rejected', $ip, ['reason' => $reason]);
    }

    /**
     * Приостановить компанию: active → suspended.
     *
     * @throws StateTransitionException если переход недопустим
     */
    public function suspend(Uuid $companyId, User $actor, ?string $ip = null): Company
    {
        return $this->apply($companyId, $actor, CompanyStatusTransition::SUSPEND->value, 'company.suspended', $ip);
    }

    /**
     * Разобрать companyId из строки; невалидный UUID трактуется как «не найдена» (404).
     *
     * @throws CompanyNotFoundException
     */
    private function resolveCompanyId(string $companyId): Uuid
    {
        if (!Uuid::isValid($companyId)) {
            throw new CompanyNotFoundException('Company not found');
        }

        return Uuid::fromString($companyId);
    }

    /**
     * Выполнить переход через workflow с фиксацией аудита.
     *
     * @param array<string, mixed>|null $extra
     *
     * @throws StateTransitionException если переход недопустим для текущего статуса
     */
    private function apply(
        Uuid $companyId,
        User $actor,
        string $transition,
        string $action,
        ?string $ip,
        ?array $extra = null,
    ): Company {
        $company = $this->companyRepository->findOrFail($companyId);
        if (!$this->companyWorkflow->can($company, $transition)) {
            throw new StateTransitionException('Invalid verification transition');
        }

        $before = $company->getVerificationStatus();
        $this->companyWorkflow->apply($company, $transition);
        if ('approve' === $transition) {
            $company->markVerified();
        }

        $this->persistWithAudit($company, $actor, $action, $before, $ip, $extra);

        return $company;
    }

    /**
     * @param array<string, mixed>|null $extra
     */
    private function persistWithAudit(
        Company $company,
        User $actor,
        string $action,
        CompanyStatusEnum $before,
        ?string $ip,
        ?array $extra = null,
    ): void {
        $after = $company->getVerificationStatus();
        $this->em->persist($company);
        $this->audit->record(
            action: $action,
            entityType: 'company',
            entityId: (string) $company->getId(),
            tenantId: (string) $company->getId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['verification_status' => $before->value],
            after: ['verification_status' => $after->value] + ($extra ?? []),
            ip: $ip,
        );
        $this->em->flush();
    }
}
