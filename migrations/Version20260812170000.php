<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Право настройки уведомлений (FR-1.6.3, задача 6.6).
 *
 * 1. Добавляет в каталог permissions код `notifications.subscribe` (группа common);
 * 2. Сеет default-матрицу (is_default=true) для manager/agent — по умолчанию
 *    включено (подписки на уведомления — self-service, доступны всем ролям
 *    компании, FR-1.6.3). ON CONFLICT DO NOTHING — суперадмин-оверрайды
 *    (is_default=false) не перетираются.
 */
final class Version20260812170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notifications.subscribe permission and default matrix rows for manager/agent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO permissions (code, name, "group", created_at) VALUES (:code, :name, :group, NOW())
             ON CONFLICT (code) DO NOTHING',
            ['code' => 'notifications.subscribe', 'name' => 'Уведомления: настройка подписок', 'group' => 'common'],
        );

        foreach (['manager', 'agent'] as $role) {
            $this->addSql(
                'INSERT INTO role_permissions (role, permission_code, enabled, is_default, updated_at)
                 VALUES (:role, :code, true, true, NOW())
                 ON CONFLICT (role, permission_code) DO NOTHING',
                ['role' => $role, 'code' => 'notifications.subscribe'],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM role_permissions WHERE permission_code = :code',
            ['code' => 'notifications.subscribe'],
        );
        $this->addSql(
            'DELETE FROM permissions WHERE code = :code',
            ['code' => 'notifications.subscribe'],
        );
    }
}
