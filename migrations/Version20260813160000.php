<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Композитные индексы каталога тендеров (FR-1.1.1, AR-6, NFR-22).
 *
 * Keyset-пагинация GET /tenders (read-модель TenderCatalogQuery) идёт по
 * (tenant_id, status) → ORDER BY created_at DESC, id DESC. Для index-сканa
 * без сортировки нужны равенство tenant_id [+ status] + диапазон created_at +
 * tiebreaker id:
 * - idx_tenders_catalog_created:  доска без фильтра статуса (default, самый частый);
 * - idx_tenders_catalog_status:   фильтр ?status= (напр. published в load-сценарии).
 * DESC по created_at — чтобы порядок ORDER BY совпадал с прямым обходом B-tree.
 * Идемпотентность: IF NOT EXISTS (повторный apply безопасен).
 */
final class Version20260813160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog keyset indexes on tenders (tenant_id, status, created_at, id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tenders_catalog_created ON tenders (tenant_id, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tenders_catalog_status ON tenders (tenant_id, status, created_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_catalog_created');
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_catalog_status');
    }
}
