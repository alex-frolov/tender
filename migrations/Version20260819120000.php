<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Индексы каталога тендеров под правило видимости (FR-1.1.1, FR-1.5.14, NFR-22).
 *
 * После расширения видимости (GET /tenders показывает не только свои тендеры,
 * но и чужие опубликованные открытые + закрытые по многоразовому договору)
 * условие каталога — OR из трёх веток; idx_tenders_catalog_* покрывают только
 * ветку своих тендеров (tenant_id). Для чужих веток добавляются:
 * - idx_tenders_catalog_access:   (access_type, status) → created_at DESC, id DESC —
 *   ветка открытых опубликованных тендеров (самая массовая);
 * - idx_tenders_catalog_customer: (customer_id, access_type, status) → created_at DESC, id DESC —
 *   ветка закрытых тендеров конкретных заказчиков (IN по договорам).
 * PostgreSQL комбинирует ветки OR через BitmapOr, каждая идёт по своему индексу.
 * Идемпотентность: IF NOT EXISTS (повторный apply безопасен).
 */
final class Version20260819120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Visibility indexes on tenders (access_type, customer_id, status, created_at, id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tenders_catalog_access ON tenders (access_type, status, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tenders_catalog_customer ON tenders (customer_id, access_type, status, created_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_catalog_access');
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_catalog_customer');
    }
}
