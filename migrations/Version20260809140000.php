<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица токенов восстановления пароля (FR-1.5.6).
 * Одноразовые токены с TTL; в БД — только sha256-хеш (как refresh_tokens).
 */
final class Version20260809140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create password_reset_tokens (FR-1.5.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_reset_tokens (id UUID NOT NULL, user_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_password_reset_tokens_token_hash ON password_reset_tokens (token_hash)');
        $this->addSql('CREATE INDEX idx_password_reset_tokens_user ON password_reset_tokens (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_reset_tokens');
    }
}
