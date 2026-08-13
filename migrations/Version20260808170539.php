<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808170539 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_users_organization');
        $this->addSql('ALTER TABLE users RENAME COLUMN organization_id TO company_id');
        $this->addSql('CREATE INDEX idx_users_company ON users (company_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_users_company');
        $this->addSql('ALTER TABLE users RENAME COLUMN company_id TO organization_id');
        $this->addSql('CREATE INDEX idx_users_organization ON users (organization_id)');
    }
}
