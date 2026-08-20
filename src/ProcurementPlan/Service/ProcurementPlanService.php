<?php

declare(strict_types=1);

namespace App\ProcurementPlan\Service;

use App\ProcurementPlan\Entity\ProcurementPlan;
use App\ProcurementPlan\Input\ProcurementPlanCreateInput;
use App\ProcurementPlan\Repository\ProcurementPlanRepository;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Планы закупок компании (procurement_plans, FR-1.5.6).
 *
 * - create(): создание плана (admin компании) с аудитом;
 * - listForCompany(): планы компании (новые сверху) для GET /procurement-plans.
 */
final readonly class ProcurementPlanService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private ProcurementPlanRepository $plans,
    ) {
    }

    /**
     * Создание плана закупок (POST /procurement-plans).
     *
     * @throws ConflictException если актор без компании
     */
    public function create(Uuid $companyId, ProcurementPlanCreateInput $input, string $actorId, ?string $ip = null): ProcurementPlan
    {
        $plan = new ProcurementPlan(
            companyId: $companyId,
            period: trim($input->period),
            items: $input->items,
        );

        $this->em->persist($plan);
        $this->em->flush();

        $this->audit->record(
            action: 'procurement_plan.created',
            entityType: 'procurement_plan',
            entityId: (string) $plan->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: $actorId,
            after: ['period' => $plan->getPeriod(), 'items_count' => \count($plan->getItems())],
            ip: $ip,
        );

        return $plan;
    }

    /**
     * @return list<ProcurementPlan>
     */
    public function listForCompany(Uuid $companyId): array
    {
        return $this->plans->listForCompany($companyId);
    }
}
