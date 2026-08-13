<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed справочников (идемпотентные data-миграции, задача 0.8):
 * - permissions: 15 стартовых кодов из domain/permissions.md (common/customer/supplier/platform);
 * - document_types: скан договора, документы заявки, документация тендера (FR-1.2.7);
 * - contract_types: base (FR-1.4.3).
 *
 * Идемпотентность: ON CONFLICT (code) DO NOTHING.
 */
final class Version20260808160100SeedDirectories extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed startovye spravochniki: permissions, document_types, contract_types';
    }

    public function up(Schema $schema): void
    {
        // --- permissions (FR-1.5.15, domain/permissions.md) ---
        $permissions = [
            // common
            ['profile.view', 'Просмотр своего профиля', 'common'],
            ['profile.update', 'Обновление своего профиля', 'common'],
            ['tenders.board.view', 'Просмотр доски тендеров', 'common'],
            // customer
            ['tenders.create', 'Создание тендера/лотов', 'customer'],
            ['tenders.publish', 'Публикация тендера', 'customer'],
            ['tenders.update', 'Изменение тендера', 'customer'],
            ['tenders.withdraw', 'Отзыв публикации (withdrawn)', 'customer'],
            ['tenders.cancel', 'Отмена тендера (причина)', 'customer'],
            ['tenders.rating', 'Оценка исполнения', 'customer'],
            // supplier
            ['bids.submit', 'Подача заявки', 'supplier'],
            ['bids.withdraw', 'Отзыв заявки', 'supplier'],
            ['auctions.join', 'Участие в аукционе', 'supplier'],
            ['contracts.sign', 'Подписание договора', 'supplier'],
            // platform
            ['platform.timezone.manage', 'Управление часовым поясом', 'platform'],
            ['platform.users.manage', 'Управление пользователями', 'platform'],
        ];
        foreach ($permissions as [$code, $name, $group]) {
            $this->addSql(
                'INSERT INTO permissions (code, name, "group", created_at) VALUES (:code, :name, :group, NOW())
                 ON CONFLICT (code) DO NOTHING',
                ['code' => $code, 'name' => $name, 'group' => $group],
            );
        }

        // --- document_types (FR-1.2.7) ---
        $docTypes = [
            ['contract_scan', 'Скан договора', 'executor', 'private', '0'],
            ['bid_documents', 'Документы заявки', 'executor', 'private', '10'],
            ['tender_documentation', 'Документация тендера', 'customer', 'public', '20'],
        ];
        foreach ($docTypes as [$code, $name, $ownerRole, $visibility, $sortOrder]) {
            $this->addSql(
                'INSERT INTO document_types (code, name, owner_role, visibility, required, auto_generated, active, sort_order, created_at)
                 VALUES (:code, :name, :ownerRole, :visibility, false, false, true, :sortOrder, NOW())
                 ON CONFLICT (code) DO NOTHING',
                ['code' => $code, 'name' => $name, 'ownerRole' => $ownerRole, 'visibility' => $visibility, 'sortOrder' => $sortOrder],
            );
        }

        // --- contract_types (FR-1.4.3) ---
        $this->addSql(
            "INSERT INTO contract_types (code, name, default_scope, active, description, created_at)
             VALUES ('base', 'Базовый договор', 'single_use', true, 'Стандартный договор по итогам тендера', NOW())
             ON CONFLICT (code) DO NOTHING",
        );
    }

    public function down(Schema $schema): void
    {
        // откат seed: удаляем только стартовые записи (идемпотентность для повторного up)
        $this->addSql("DELETE FROM permissions WHERE code IN ('profile.view','profile.update','tenders.board.view','tenders.create','tenders.publish','tenders.update','tenders.withdraw','tenders.cancel','tenders.rating','bids.submit','bids.withdraw','auctions.join','contracts.sign','platform.timezone.manage','platform.users.manage')");
        $this->addSql("DELETE FROM document_types WHERE code IN ('contract_scan','bid_documents','tender_documentation')");
        $this->addSql("DELETE FROM contract_types WHERE code = 'base'");
    }
}
