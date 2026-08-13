<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Права на сохранённые поиски и избранное (F-A5/A6, AM-12, задача 6.7).
 *
 * 1. Добавляет в каталог permissions коды `search.save` (сохранённые поиски)
 *    и `favorites.manage` (избранное/заметки) — группа common;
 * 2. Сеет default-матрицу (is_default=true) для manager/agent — по умолчанию
 *    включено (self-service «мои данные»: доступно всем ролям компании,
 *    как notifications.subscribe, FR-1.6.3). ON CONFLICT DO NOTHING —
 *    суперадмин-оверрайды (is_default=false) не перетираются.
 */
final class Version20260812190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add search.save and favorites.manage permissions with default matrix rows for manager/agent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO permissions (code, name, "group", created_at) VALUES (:code, :name, :group, NOW())
             ON CONFLICT (code) DO NOTHING',
            ['code' => 'search.save', 'name' => 'Поиск: сохранённые шаблоны поиска', 'group' => 'common'],
        );
        $this->addSql(
            'INSERT INTO permissions (code, name, "group", created_at) VALUES (:code, :name, :group, NOW())
             ON CONFLICT (code) DO NOTHING',
            ['code' => 'favorites.manage', 'name' => 'Избранное: метки и заметки по тендеру', 'group' => 'common'],
        );

        foreach (['manager', 'agent'] as $role) {
            foreach (['search.save', 'favorites.manage'] as $code) {
                $this->addSql(
                    'INSERT INTO role_permissions (role, permission_code, enabled, is_default, updated_at)
                     VALUES (:role, :code, true, true, NOW())
                     ON CONFLICT (role, permission_code) DO NOTHING',
                    ['role' => $role, 'code' => $code],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['search.save', 'favorites.manage'] as $code) {
            $this->addSql(
                'DELETE FROM role_permissions WHERE permission_code = :code',
                ['code' => $code],
            );
            $this->addSql(
                'DELETE FROM permissions WHERE code = :code',
                ['code' => $code],
            );
        }
    }
}
