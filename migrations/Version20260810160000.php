<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица idempotency_keys (AR-4, modules.md → platform).
 *
 * Единый механизм идемпотентности мутаций: повторный запрос с тем же
 * Idempotency-Key и тем же request_hash → сохранённый ответ; другой хэш →
 * 409 idempotency_conflict. tenant_id nullable (анонимные мутации), поэтому
 * уникальность key обеспечивает функциональный индекс COALESCE(tenant_id,'')+key.
 * expires_at — TTL retention (retention idempotency_keys).
 */
final class Version20260810160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create idempotency_keys table (AR-4, unified idempotency mechanism)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE idempotency_keys (id BIGSERIAL NOT NULL, tenant_id VARCHAR(36) DEFAULT NULL, key VARCHAR(255) NOT NULL, method VARCHAR(10) NOT NULL, path VARCHAR(500) NOT NULL, request_hash VARCHAR(64) NOT NULL, response_status INT DEFAULT NULL, response_body JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql("CREATE UNIQUE INDEX uniq_idempotency_tenant_key ON idempotency_keys (COALESCE(tenant_id, ''), key)");
        $this->addSql('CREATE INDEX idx_idempotency_expires ON idempotency_keys (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idempotency_keys');
    }
}
