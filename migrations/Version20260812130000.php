<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Фаза 6 (6.4): экспорт (UC-31, AM-15).
 *
 * export_jobs — фоновые задачи экспорта данных компании-тенанта:
 * - export_type (tenders/bids/contracts), format (xlsx/csv), filters (json);
 * - status: queued → processing → ready/failed (ExportJobStatusEnum);
 * - storage_path/file_name/file_size — готовый файл (ExportFileStorage),
 *   error — причина провала; requested_by — актор-инициатор.
 *
 * Файл НЕ хранится в БД (только метаданные); бинарное содержимое — на диске
 * (var/exports), как документы (AM-8).
 */
final class Version20260812130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create export jobs table (UC-31, AM-15)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE export_jobs (id UUID NOT NULL, tenant_id UUID NOT NULL, export_type VARCHAR(20) NOT NULL, format VARCHAR(10) NOT NULL, filters JSON DEFAULT NULL, status VARCHAR(20) DEFAULT 'queued' NOT NULL, storage_path VARCHAR(255) DEFAULT NULL, file_name VARCHAR(255) DEFAULT NULL, file_size BIGINT DEFAULT NULL, error TEXT DEFAULT NULL, requested_by UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_export_jobs_tenant_status ON export_jobs (tenant_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE export_jobs');
    }
}
