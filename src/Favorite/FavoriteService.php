<?php

declare(strict_types=1);

namespace App\Favorite;

use App\Favorite\Entity\Enum\FavoriteEntityTypeEnum;
use App\Favorite\Entity\Favorite;
use App\Favorite\Exception\DuplicateFavoriteException;
use App\Favorite\Exception\FavoriteNotFoundException;
use App\Favorite\Input\AddFavoriteInput;
use App\Favorite\Repository\FavoriteRepository;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Избранное/метки/заметки по тендеру (F-A6, UC-17, AM-12, openapi /favorites).
 *
 * Self-service «моё избранное»: каждый пользователь ведёт собственный список
 * избранных тендеров/лотов с заметками. Запись привязывается к пользователю
 * (user_id) и его компании-тенанту (tenant_id). Другие пользователи чужую
 * запись не видят — 404 (tenant-изоляция на уровне актора).
 *
 * - add — добавление (entity_type tender/lot, entity_id, note);
 * - list — список избранного пользователя;
 * - delete — удаление из избранного.
 *
 * Уникальность (user_id, entity_type, entity_id): повторное добавление той же
 * сущности тем же пользователем — 409 duplicate_favorite.
 */
final readonly class FavoriteService
{
    public function __construct(
        private EntityManagerInterface $em,
        private FavoriteRepository $favorites,
        private AuditService $audit,
    ) {
    }

    /**
     * Добавление в избранное (F-A6, POST /favorites). Тенант — компания актора.
     *
     * @throws ConflictException          если актор без компании
     * @throws DuplicateFavoriteException если сущность уже в избранном
     *                                    (duplicate_favorite)
     * @throws ValidationException        если entity_type/entity_id невалидны
     */
    public function add(User $actor, AddFavoriteInput $input): Favorite
    {
        $tenantId = $this->requireCompany($actor);
        $entityType = $this->entityType($input->entity_type);
        $entityId = $this->entityId($input->entity_id);

        if (null !== $this->favorites->findByUserEntity($actor->getId(), $entityType, $entityId)) {
            throw new DuplicateFavoriteException('Favorite already exists');
        }

        $favorite = new Favorite(
            userId: $actor->getId(),
            tenantId: $tenantId,
            entityType: $entityType,
            entityId: $entityId,
            note: $this->note($input->note),
        );

        $this->em->persist($favorite);
        $this->em->flush();

        $this->audit->record(
            action: 'favorite.created',
            entityType: 'favorite',
            entityId: (string) $favorite->getId(),
            tenantId: (string) $tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'entity_type' => $favorite->getEntityType()->value,
                'entity_id' => (string) $favorite->getEntityId(),
            ],
        );
        $this->em->flush();

        return $favorite;
    }

    /**
     * Список избранного пользователя (GET /favorites).
     *
     * @return list<Favorite>
     */
    public function list(User $actor): array
    {
        return $this->favorites->listForUser($actor->getId());
    }

    /**
     * Удаление из избранного (DELETE /favorites?favoriteId=...).
     * Возвращает id удалённой записи.
     *
     * @throws FavoriteNotFoundException
     */
    public function delete(User $actor, string $favoriteId): string
    {
        $favorite = $this->resolveOwned($actor, $favoriteId);
        $id = (string) $favorite->getId();
        $tenantId = (string) $favorite->getTenantId();

        $this->em->remove($favorite);
        $this->em->flush();

        $this->audit->record(
            action: 'favorite.deleted',
            entityType: 'favorite',
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
     * @throws FavoriteNotFoundException если запись не найдена или принадлежит
     *                                   другому пользователю
     */
    private function resolveOwned(User $actor, string $favoriteId): Favorite
    {
        $favorite = $this->favorites->findById($favoriteId);
        if (null === $favorite || !$favorite->getUserId()->equals($actor->getId())) {
            throw new FavoriteNotFoundException('Favorite not found');
        }

        return $favorite;
    }

    /**
     * @throws ValidationException
     */
    private function entityType(string $value): FavoriteEntityTypeEnum
    {
        return FavoriteEntityTypeEnum::tryFrom($value)
            ?? throw new ValidationException('invalid entity_type');
    }

    /**
     * @throws ValidationException
     */
    private function entityId(string $value): Uuid
    {
        if (!Uuid::isValid($value)) {
            throw new ValidationException('invalid entity_id');
        }

        return Uuid::fromString($value);
    }

    /**
     * Заметка/метка (F-A6): пустая строка → null.
     */
    private function note(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return trim($value);
    }
}
