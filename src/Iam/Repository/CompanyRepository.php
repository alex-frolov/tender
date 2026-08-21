<?php

declare(strict_types=1);

namespace App\Iam\Repository;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Exception\CompanyNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Репозиторий компании (тенанта, FR-1.5.4).
 *
 * Единая точка загрузки компании с 404-семантикой: findOrFail бросает
 * CompanyNotFoundException (→ 404 через JsonApiExceptionSubscriber). Используется
 * вместо ручного `$em->getRepository(Company::class)->find(...)` в сервисах
 * и контроллерах (см. AGENTS.md, правило entity-bound update forms).
 *
 * listPage() — реестр компаний площадки для модерации суперадмином
 * (GET /admin/companies): keyset-страница по (created_at, id) DESC.
 *
 * @extends ServiceEntityRepository<Company>
 */
final class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    /**
     * Компания по id или 404.
     *
     * @throws CompanyNotFoundException если id не указан или компания не найдена
     */
    public function findOrFail(?Uuid $id): Company
    {
        $company = null === $id ? null : $this->find($id);
        if (null === $company) {
            throw new CompanyNotFoundException('Company not found');
        }

        return $company;
    }

    /**
     * Поиск подтверждённых компаний по названию или ИНН (GET /companies/search).
     *
     * Только `active`: незавершённая и отклонённая модерация — внутреннее
     * состояние площадки, показывать его всем участникам незачем, да и выбрать
     * такую компанию стороной договора всё равно нельзя.
     *
     * Сортировка по названию: результат читает человек, выбирающий контрагента,
     * а не постраничный обход — курсора здесь нет, есть жёсткий лимит.
     *
     * @return list<Company>
     */
    public function search(string $query, int $limit): array
    {
        $qb = $this->createQueryBuilder('c');

        /** @var list<Company> $result */
        $result = $qb
            ->where('c.verificationStatus = :active')
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(c.legalName)', ':q'),
                    $qb->expr()->like('LOWER(c.inn)', ':q'),
                ),
            )
            ->setParameter('active', CompanyStatusEnum::ACTIVE->value)
            ->setParameter('q', '%'.mb_strtolower(trim($query)).'%')
            ->orderBy('c.legalName', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Страница реестра компаний (FR-1.5.7, GET /admin/companies): все компании
     * площадки, новые сверху, keyset-срез по (created_at, id) DESC (AR-6).
     * Tenant-изоляции здесь нет намеренно — это экран модерации platform_admin
     * (доступ ограничен CompanyVoter::VERIFY на контроллере).
     *
     * $limit вызывающий передаёт как limit+1 — «есть ли следующая страница»
     * определяется по лишней строке, без COUNT.
     *
     * @return list<Company>
     */
    public function listPage(
        ?CompanyStatusEnum $status,
        ?string $q,
        ?\DateTimeImmutable $cursorCreatedAt,
        ?Uuid $cursorId,
        int $limit,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        if (null !== $status) {
            $qb->andWhere('c.verificationStatus = :status')
                ->setParameter('status', $status->value);
        }

        if (null !== $q && '' !== trim($q)) {
            // Подстрока без учёта регистра по названию и ИНН — поиск карточки
            // на экране модерации; точное совпадение не требуется.
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(c.legalName)', ':q'),
                    $qb->expr()->like('LOWER(c.inn)', ':q'),
                ),
            )->setParameter('q', '%'.mb_strtolower(trim($q)).'%');
        }

        if (null !== $cursorCreatedAt && null !== $cursorId) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->lt('c.createdAt', ':cursorCreatedAt'),
                    $qb->expr()->andX(
                        $qb->expr()->eq('c.createdAt', ':cursorCreatedAt'),
                        $qb->expr()->lt('c.id', ':cursorId'),
                    ),
                ),
            )
                ->setParameter('cursorCreatedAt', $cursorCreatedAt)
                ->setParameter('cursorId', $cursorId);
        }

        /** @var list<Company> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
