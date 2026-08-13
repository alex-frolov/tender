<?php

declare(strict_types=1);

namespace App\SavedSearch;

use App\Iam\Entity\User;
use App\SavedSearch\Entity\Enum\SavedSearchDigestPeriodEnum;
use App\SavedSearch\Entity\SavedSearch;
use App\SavedSearch\Exception\SavedSearchNotFoundException;
use App\SavedSearch\Input\CreateSavedSearchInput;
use App\SavedSearch\Repository\SavedSearchRepository;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Сохранённые шаблоны поиска (F-A5, UC-17, AM-12, openapi /saved-searches).
 *
 * Self-service «мои сохранённые поиски»: каждый пользователь управляет своими
 * шаблонами. Шаблон привязывается к пользователю (user_id) и его компании-тенанту
 * (tenant_id). Другие пользователи чужой шаблон не видят — 404 (tenant-изоляция
 * на уровне актора).
 *
 * - create — создание шаблона (имя + фильтры + периодичность автопоиска);
 * - list — список шаблонов пользователя;
 * - delete — удаление шаблона.
 *
 * Сервис — оркестратор: валидация (имя, фильтры, периодичность, принадлежность)
 * и фиксация (persist + append-only аудит FR-1.8). Автопоиск по расписанию
 * (digest) — периодичность хранится здесь, рассылка дайджеста — модуль
 * уведомлений (NotificationDigestService, FR-1.6).
 */
final readonly class SavedSearchService
{
    public function __construct(
        private EntityManagerInterface $em,
        private SavedSearchRepository $searches,
        private AuditService $audit,
    ) {
    }

    /**
     * Создание сохранённого поиска (F-A5, POST /saved-searches).
     * Тенант шаблона — компания актора; без компании (platform_admin вне
     * тенанта) сохранение недоступно.
     *
     * @throws ConflictException   если актор без компании
     * @throws ValidationException если имя пустое или периодичность невалидна
     */
    public function create(User $actor, CreateSavedSearchInput $input): SavedSearch
    {
        $tenantId = $this->requireCompany($actor);

        $savedSearch = new SavedSearch(
            userId: $actor->getId(),
            tenantId: $tenantId,
            name: $this->name($input->name),
            filters: $this->filters($input->filters),
            digestPeriod: $this->digestPeriod($input->digest_period),
        );

        $this->em->persist($savedSearch);
        $this->em->flush();

        $this->audit->record(
            action: 'saved_search.created',
            entityType: 'saved_search',
            entityId: (string) $savedSearch->getId(),
            tenantId: (string) $tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'name' => $savedSearch->getName(),
                'digest_period' => $savedSearch->getDigestPeriod()->value,
            ],
        );
        $this->em->flush();

        return $savedSearch;
    }

    /**
     * Список сохранённых поисков пользователя (GET /saved-searches).
     *
     * @return list<SavedSearch>
     */
    public function list(User $actor): array
    {
        return $this->searches->listForUser($actor->getId());
    }

    /**
     * Удаление сохранённого поиска (DELETE /saved-searches?savedSearchId=...).
     * Возвращает id удалённого шаблона.
     *
     * @throws SavedSearchNotFoundException
     */
    public function delete(User $actor, string $savedSearchId): string
    {
        $savedSearch = $this->resolveOwned($actor, $savedSearchId);
        $id = (string) $savedSearch->getId();
        $tenantId = (string) $savedSearch->getTenantId();

        $this->em->remove($savedSearch);
        $this->em->flush();

        $this->audit->record(
            action: 'saved_search.deleted',
            entityType: 'saved_search',
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
     * @throws SavedSearchNotFoundException если шаблон не найден или принадлежит
     *                                      другому пользователю
     */
    private function resolveOwned(User $actor, string $savedSearchId): SavedSearch
    {
        $savedSearch = $this->searches->findById($savedSearchId);
        if (null === $savedSearch || !$savedSearch->getUserId()->equals($actor->getId())) {
            throw new SavedSearchNotFoundException('Saved search not found');
        }

        return $savedSearch;
    }

    /**
     * @throws ValidationException если имя пустое
     */
    private function name(string $value): string
    {
        if ('' === trim($value)) {
            throw new ValidationException('name must not be empty');
        }

        return trim($value);
    }

    /**
     * Фильтры поиска (F-A5): обязательный JSON-объект.
     *
     * @param array<string, mixed>|null $value
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function filters(?array $value): array
    {
        if (null === $value || [] === $value) {
            throw new ValidationException('filters must not be empty');
        }

        return $value;
    }

    /**
     * @throws ValidationException
     */
    private function digestPeriod(?string $value): SavedSearchDigestPeriodEnum
    {
        if (null === $value || '' === $value) {
            return SavedSearchDigestPeriodEnum::NONE;
        }

        return SavedSearchDigestPeriodEnum::tryFrom($value)
            ?? throw new ValidationException('invalid digest_period');
    }
}
