<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Фаза 6 (6.7): сохранённые поиски и избранное (F-A5/A6, AM-12, UC-17).
 *
 * - saved_searches — сохранённый шаблон поиска пользователя: имя, фильтры
 *   (jsonb), периодичность автопоиска (none/daily/weekly), флаг активности.
 *   Принадлежит пользователю (user_id) и его компании-тенанту (tenant_id);
 * - favorites — избранное/метка/заметка по тендеру или лоту (entity_type
 *   tender/lot, entity_id). unique (user_id, entity_type, entity_id) — один
 *   пользователь добавляет конкретную сущность в избранное один раз.
 *
 * Хранение фильтров — JSON (как notification_subscriptions.filters): поисковый
 * слой интерпретирует фильтры при выполнении поиска/автопоиска.
 */
final class Version20260812180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create saved searches and favorites tables (F-A5/A6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE saved_searches (id UUID NOT NULL, user_id UUID NOT NULL, tenant_id UUID NOT NULL, name VARCHAR(200) NOT NULL, filters JSON NOT NULL, digest_period VARCHAR(10) NOT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_saved_searches_user ON saved_searches (user_id)');
        $this->addSql('CREATE INDEX idx_saved_searches_tenant_digest_active ON saved_searches (tenant_id, digest_period, active)');

        $this->addSql('CREATE TABLE favorites (id UUID NOT NULL, user_id UUID NOT NULL, tenant_id UUID NOT NULL, entity_type VARCHAR(10) NOT NULL, entity_id UUID NOT NULL, note VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_favorites_user_entity ON favorites (user_id, entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_favorites_user ON favorites (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE favorites');
        $this->addSql('DROP TABLE saved_searches');
    }
}
