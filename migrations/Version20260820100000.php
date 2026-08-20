<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Автор жалобы: complaints.company_id (FR-1.2.10).
 *
 * Жалоба подаётся участником, но до сих пор не хранила, кем именно: подателя
 * нельзя было ни показать, ни ограничить выдачу тенантом при рассмотрении.
 * Колонка обязательная; уже существующие строки (таблица создана
 * Version20260818191053 и в проде не наполнялась) заполняются нулевым UUID —
 * «автор неизвестен», чтобы миграция не падала на непустой базе.
 * Индекс idx_complaints_company — под выборку жалоб компании.
 */
final class Version20260820100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add company_id (complaint author) to complaints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaints ADD COLUMN IF NOT EXISTS company_id UUID');
        $this->addSql("UPDATE complaints SET company_id = '00000000-0000-0000-0000-000000000000' WHERE company_id IS NULL");
        $this->addSql('ALTER TABLE complaints ALTER COLUMN company_id SET NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_complaints_company ON complaints (company_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_complaints_company');
        $this->addSql('ALTER TABLE complaints DROP COLUMN IF EXISTS company_id');
    }
}
