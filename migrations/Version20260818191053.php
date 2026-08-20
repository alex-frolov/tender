<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Новые модули (2026-08-18): okpd2 для тендеров, профили поставщиков,
 * планы закупок, вопросы/жалобы по тендеру, настройки платформы.
 */
final class Version20260818191053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add okpd2 to tenders and new tables: supplier_profiles, procurement_plans, tender_questions, complaints, platform_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenders ADD okpd2 VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE TABLE supplier_profiles (id UUID NOT NULL, company_id UUID NOT NULL, categories JSON NOT NULL, capabilities JSON NOT NULL, documents JSON NOT NULL, rating DOUBLE PRECISION DEFAULT NULL, rnp_blocked BOOLEAN DEFAULT false NOT NULL, checks JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_supplier_profiles_company ON supplier_profiles (company_id)');
        $this->addSql('CREATE TABLE procurement_plans (id UUID NOT NULL, company_id UUID NOT NULL, period VARCHAR(20) NOT NULL, status VARCHAR(20) DEFAULT \'draft\' NOT NULL, items JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_procurement_plans_company ON procurement_plans (company_id)');
        $this->addSql('CREATE TABLE tender_questions (id UUID NOT NULL, tender_id UUID NOT NULL, lot_id UUID DEFAULT NULL, text TEXT NOT NULL, answer TEXT DEFAULT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_tender_questions_tender ON tender_questions (tender_id)');
        $this->addSql('CREATE TABLE complaints (id UUID NOT NULL, tender_id UUID NOT NULL, lot_id UUID DEFAULT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, text TEXT NOT NULL, ground TEXT NOT NULL, document_ids JSON NOT NULL, resolution TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_complaints_tender ON complaints (tender_id)');
        $this->addSql('CREATE TABLE platform_settings (key VARCHAR(100) NOT NULL, value TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (key))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_settings');
        $this->addSql('DROP TABLE complaints');
        $this->addSql('DROP TABLE tender_questions');
        $this->addSql('DROP TABLE procurement_plans');
        $this->addSql('DROP TABLE supplier_profiles');
        $this->addSql('ALTER TABLE tenders DROP okpd2');
    }
}
