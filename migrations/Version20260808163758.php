<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808163758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE organizations (id UUID NOT NULL, type VARCHAR(20) NOT NULL, legal_name VARCHAR(255) NOT NULL, inn VARCHAR(12) NOT NULL, kpp VARCHAR(12) DEFAULT NULL, ogrn VARCHAR(20) DEFAULT NULL, address VARCHAR(500) DEFAULT NULL, contacts JSON DEFAULT NULL, verification_status VARCHAR(20) DEFAULT \'pending\' NOT NULL, verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, timezone_default VARCHAR(50) DEFAULT \'UTC\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_427C1C7FE93323CB ON organizations (inn)');
        $this->addSql('CREATE TABLE refresh_tokens (id UUID NOT NULL, user_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9BACE7E1B3BC57DA ON refresh_tokens (token_hash)');
        $this->addSql('CREATE INDEX idx_refresh_tokens_user ON refresh_tokens (user_id)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, organization_id UUID DEFAULT NULL, email VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, role VARCHAR(100) NOT NULL, verification_status VARCHAR(20) DEFAULT \'email_pending\' NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, two_factor_enabled BOOLEAN NOT NULL, totp_secret VARCHAR(64) DEFAULT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, masked_email VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE INDEX idx_users_organization ON users (organization_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE organizations');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE users');
    }
}
