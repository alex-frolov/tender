<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблицы documents и document_versions (AM-8, FR-1.1.5, FR-1.2.6/1.2.7).
 *
 * documents — агрегат документа (тип, владелец, видимость, scope, привязка к
 * сущности tender/lot/bid/contract/claim, tenant). document_versions — версии
 * файла (file_id, version, sha256, размер, mime, путь в хранилище).
 * Бинарное содержимое — в файловом хранилище (FileStorage), не в БД.
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create documents and document_versions tables (AM-8, FR-1.1.5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE documents (id UUID NOT NULL, document_type_id BIGINT NOT NULL, entity_type VARCHAR(20) NOT NULL, entity_id UUID NOT NULL, title VARCHAR(500) NOT NULL, owner_role VARCHAR(20) NOT NULL, visibility VARCHAR(20) NOT NULL, scope VARCHAR(20) NOT NULL, is_auto_generated BOOLEAN DEFAULT false NOT NULL, tenant_id UUID NOT NULL, created_by UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_documents_entity ON documents (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_documents_tenant ON documents (tenant_id)');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_DOCUMENTS_TYPE FOREIGN KEY (document_type_id) REFERENCES document_types (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE document_versions (id UUID NOT NULL, document_id UUID NOT NULL, version INT NOT NULL, sha256 VARCHAR(64) NOT NULL, size_bytes BIGINT NOT NULL, mime_type VARCHAR(127) NOT NULL, original_name VARCHAR(500) NOT NULL, storage_path VARCHAR(500) NOT NULL, uploaded_by UUID NOT NULL, uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_document_versions_document ON document_versions (document_id)');
        $this->addSql('ALTER TABLE document_versions ADD CONSTRAINT FK_DOCUMENT_VERSIONS_DOCUMENT FOREIGN KEY (document_id) REFERENCES documents (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE document_versions');
        $this->addSql('DROP TABLE documents');
    }
}
