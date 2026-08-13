<?php

declare(strict_types=1);

namespace App\Iam\Entity;

use App\Iam\Entity\Enum\RolePermissionRoleEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Настройка прав ролей manager/agent (FR-1.5.10, FR-1.5.15).
 * Таблица: role_permissions. unique (role, permission_code).
 *
 * - admin — фиксированный полный набор, НЕ хранится здесь;
 * - manager/agent — наборы (enabled/disabled), управляются суперадмином;
 * - is_default=true — базовый набор (default-матрица из domain/permissions.md);
 *   при отсутствии записи для роли поиск падает на default-матрицу;
 * - изменение набора применяется немедленно (проверка читает актуальное состояние БД).
 */
#[ORM\Entity]
#[ORM\Table(name: 'role_permissions')]
#[ORM\UniqueConstraint(name: 'uniq_role_permissions_role_code', columns: ['role', 'permission_code'])]
class RolePermission
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    #[ORM\GeneratedValue]
    /** @var int|null Doctrine присваивает id через reflection */
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 20, enumType: RolePermissionRoleEnum::class)]
    private RolePermissionRoleEnum $role;

    #[ORM\Column(length: 100)]
    private string $permissionCode;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column(name: 'is_default')]
    private bool $isDefault;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $updatedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        RolePermissionRoleEnum $role,
        string $permissionCode,
        bool $enabled,
        bool $isDefault = false,
        ?Uuid $updatedBy = null,
    ) {
        $this->role = $role;
        $this->permissionCode = $permissionCode;
        $this->enabled = $enabled;
        $this->isDefault = $isDefault;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): RolePermissionRoleEnum
    {
        return $this->role;
    }

    public function getPermissionCode(): string
    {
        return $this->permissionCode;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getUpdatedBy(): ?Uuid
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Изменение набора суперадмином: обновляется enabled, снимается флаг
     * default (запись теперь явно задана), фиксируется actor и время.
     */
    public function configure(bool $enabled, ?Uuid $updatedBy): void
    {
        $this->enabled = $enabled;
        $this->isDefault = false;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
