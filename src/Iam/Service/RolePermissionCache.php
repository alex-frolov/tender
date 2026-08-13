<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\Enum\RolePermissionRoleEnum;
use App\Iam\Repository\RolePermissionRepository;

/**
 * Кэш наборов прав ролей manager/agent (FR-1.5.15).
 *
 * Данных немного (роли × коды), поэтому кэшируются сразу оба набора одним
 * ключом в Redis: {role: {code: enabled}}. PermissionCheckService читает
 * отсюда в первую очередь; RolePermissionService инвалидирует кэш при
 * изменении набора — изменение применяется немедленно.
 */
final class RolePermissionCache
{
    private const string KEY = 'role_permissions:enabled';

    public function __construct(
        private readonly \Redis $redis,
        private readonly RolePermissionRepository $rolePermissions,
    ) {
    }

    /**
     * Получить оба набора (role → code → enabled): из кэша, при промахе —
     * из БД (default-матрица) и записать в кэш.
     *
     * @return array<string, array<string, bool>> role value → code → enabled
     */
    public function all(): array
    {
        $cached = $this->cached();
        if (null !== $cached) {
            return $cached;
        }

        $map = $this->build();
        $this->save($map);

        return $map;
    }

    /**
     * Инвалидация кэша после изменения набора (FR-1.5.15: применяется немедленно).
     */
    public function clear(): void
    {
        $this->redis->del(self::KEY);
    }

    /**
     * @return array<string, array<string, bool>>|null null при промахе кэша
     */
    private function cached(): ?array
    {
        $raw = $this->redis->get(self::KEY);
        if (!\is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, array<string, bool>> $decoded */
        return $decoded;
    }

    /**
     * Собрать оба набора из БД (включая default-строки is_default=true).
     *
     * @return array<string, array<string, bool>>
     */
    private function build(): array
    {
        $map = [];
        foreach ([RolePermissionRoleEnum::MANAGER, RolePermissionRoleEnum::AGENT] as $role) {
            $rowMap = [];
            foreach ($this->rolePermissions->findByRole($role) as $row) {
                $rowMap[$row->getPermissionCode()] = $row->isEnabled();
            }
            $map[$role->value] = $rowMap;
        }

        return $map;
    }

    /**
     * @param array<string, array<string, bool>> $map
     */
    private function save(array $map): void
    {
        $json = json_encode($map, \JSON_UNESCAPED_UNICODE);
        if (false !== $json) {
            $this->redis->set(self::KEY, $json);
        }
    }
}
