<?php

declare(strict_types=1);

namespace App\Contract;

use App\Contract\Entity\ContractType;
use App\Contract\Input\CreateContractTypeInput;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Справочник типов договоров (FR-1.4.3, contract_types). Управляется
 * суперадмином платформы; стартовый набор — base (seed 0.8).
 *
 * - list(): активные типы (справочник для всех — выбор типа при заключении);
 * - create(): суперадмин; code уникален; default_scope задаётся через
 *   is_single_use (single_use/multi_use, FR-1.4.6).
 * Каждая мутация пишет append-only запись в аудит (FR-1.8).
 */
final readonly class ContractTypeService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
    ) {
    }

    /**
     * Активные типы договоров (справочник, FR-1.4.3).
     *
     * @return list<ContractType>
     */
    public function list(): array
    {
        $result = $this->em->getRepository(ContractType::class)
            ->createQueryBuilder('t')
            ->andWhere('t.active = true')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<ContractType> */
        return $result;
    }

    /**
     * Создание типа договора суперадмином (FR-1.4.3, POST /contract-types).
     *
     * @throws ConflictException   если code уже занят
     * @throws ValidationException если code/name не заполнены
     */
    public function create(User $actor, CreateContractTypeInput $input, ?string $ip = null): ContractType
    {
        $code = trim($input->code);
        $name = trim($input->name);
        if ('' === $code) {
            throw new ValidationException('code is required');
        }
        if ('' === $name) {
            throw new ValidationException('name is required');
        }

        $existing = $this->em->getRepository(ContractType::class)->findOneBy(['code' => $code]);
        if (null !== $existing) {
            throw new ConflictException('Contract type code already exists');
        }

        $type = new ContractType(
            code: $code,
            name: $name,
            defaultScope: $input->isSingleUse ? 'single_use' : 'multi_use',
            description: null,
        );

        $this->em->persist($type);
        $this->em->flush();

        $this->audit->record(
            action: 'contract_type.created',
            entityType: 'contract_type',
            entityId: (string) $type->getId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'code' => $type->getCode(),
                'name' => $type->getName(),
                'default_scope' => $type->getDefaultScope(),
            ],
            ip: $ip,
        );

        return $type;
    }
}
