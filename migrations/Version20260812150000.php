<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Фаза 6 (6.5): API-ключи (FR-1.5.13, AR-3).
 *
 * api_keys — ключи доступа к API компании-тенанта:
 * - token_hash — SHA-256 хэш raw-токена (сам raw-токен в БД не хранится,
 *   отдаётся один раз при создании/ротации — AR-3);
 * - scopes — набор прав ключа (jsonb, каталог ApiKeyScopes);
 * - expires_at — срок действия (nullable — без срока);
 * - last_used_at — последняя успешная аутентификация по ключу;
 * - revoked_at — отзыв ключа (revoke/ротация аннулирует старый raw-токен).
 *
 * Аутентификация по ключу (X-API-Key / Bearer) действует от имени
 * пользователя-владельца (PAT), но ограничивает его права до scopes ключа
 * (ScopedPermissionChecker).
 */
final class Version20260812150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create API keys table (FR-1.5.13, AR-3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_keys (id UUID NOT NULL, tenant_id UUID NOT NULL, user_id UUID NOT NULL, name VARCHAR(100) NOT NULL, token_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_api_keys_token_hash ON api_keys (token_hash)');
        $this->addSql('CREATE INDEX idx_api_keys_tenant ON api_keys (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_keys');
    }
}
