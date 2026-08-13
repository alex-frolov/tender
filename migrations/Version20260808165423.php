<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Переименование таблицы organizations → companies (сущность Company).
 * Данные сохраняются: ALTER TABLE ... RENAME TO + переименование индексов.
 */
final class Version20260808165423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename table organizations to companies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organizations RENAME TO companies');
        $this->addSql('ALTER INDEX uniq_427c1c7fe93323cb RENAME TO uniq_8244aa3ae93323cb');
        $this->addSql('ALTER INDEX organizations_pkey RENAME TO companies_pkey');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies RENAME TO organizations');
        $this->addSql('ALTER INDEX uniq_8244aa3ae93323cb RENAME TO uniq_427c1c7fe93323cb');
        $this->addSql('ALTER INDEX companies_pkey RENAME TO organizations_pkey');
    }
}
