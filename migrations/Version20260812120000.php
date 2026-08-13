<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Право дашборда/аналитики (AM-13, задача 6.3).
 *
 * 1. Добавляет в каталог permissions код `dashboard.view` (группа common);
 * 2. Сеет default-матрицу (is_default=true) для manager/agent — по умолчанию
 *    включено (дашборд виден всем ролям компании). ON CONFLICT DO NOTHING —
 *    суперадмин-оверрайды (is_default=false) не перетираются.
 */
final class Version20260812120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dashboard.view permission and default matrix rows for manager/agent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO permissions (code, name, "group", created_at) VALUES (:code, :name, :group, NOW())
             ON CONFLICT (code) DO NOTHING',
            ['code' => 'dashboard.view', 'name' => 'Дашборд и аналитика', 'group' => 'common'],
        );

        foreach (['manager', 'agent'] as $role) {
            $this->addSql(
                'INSERT INTO role_permissions (role, permission_code, enabled, is_default, updated_at)
                 VALUES (:role, :code, true, true, NOW())
                 ON CONFLICT (role, permission_code) DO NOTHING',
                ['role' => $role, 'code' => 'dashboard.view'],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM role_permissions WHERE permission_code = :code',
            ['code' => 'dashboard.view'],
        );
        $this->addSql(
            'DELETE FROM permissions WHERE code = :code',
            ['code' => 'dashboard.view'],
        );
    }
}
