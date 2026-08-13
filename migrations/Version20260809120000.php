<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица токенов подтверждения email (FR-1.5.5).
 * Одноразовые токены с TTL; в БД — только sha256-хеш (как refresh_tokens).
 */
final class Version20260809120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create email_verification_tokens (FR-1.5.5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_verification_tokens (id UUID NOT NULL, user_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_verification_tokens_token_hash ON email_verification_tokens (token_hash)');
        $this->addSql('CREATE INDEX idx_email_verification_tokens_user ON email_verification_tokens (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_verification_tokens');
    }
}
