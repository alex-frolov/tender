<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица contracts (FR-1.4.3, domain/contract-state-machine.md, M5).
 *
 * Договоры — самостоятельная сущность: рамочные (source=external, FR-1.4.8)
 * для закрытых тендеров (contract_holders, FR-1.5.14) и по итогам тендера
 * (source=tender, связь contract_tenders — задача 5.4). Статус — через
 * symfony/workflow (config/workflow/contract.yaml): draft → pending_signature
 * → signed → registered; terminated/expired/deleted.
 *
 * Подписание (ЭП-заглушка): флаги signed_by_customer/signed_by_supplier +
 * строка-подпись каждой стороны; переход к signed только при обеих подписях.
 * Деньги — bigint minor units (PR-1); цена рамочного договора может отсутствовать.
 */
final class Version20260810150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contracts table (FR-1.4.3, contract state machine)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE contracts (id UUID NOT NULL, tenant_id UUID NOT NULL, number VARCHAR(64) NOT NULL, contract_type_id BIGINT NOT NULL, customer_id UUID NOT NULL, supplier_id UUID NOT NULL, source VARCHAR(20) NOT NULL, award_id UUID DEFAULT NULL, price_net_minor BIGINT DEFAULT NULL, price_gross_minor BIGINT DEFAULT NULL, vat_rate_bps INT DEFAULT 0 NOT NULL, price_basis VARCHAR(10) DEFAULT NULL, currency VARCHAR(3) DEFAULT 'RUB' NOT NULL, status VARCHAR(30) DEFAULT 'draft' NOT NULL, scope VARCHAR(20) DEFAULT 'multi_use' NOT NULL, valid_from DATE DEFAULT NULL, valid_to DATE DEFAULT NULL, signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, registered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, terminated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, terms JSON DEFAULT NULL, signed_by_customer BOOLEAN DEFAULT false NOT NULL, signed_by_supplier BOOLEAN DEFAULT false NOT NULL, signature_customer TEXT DEFAULT NULL, signature_supplier TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CONTRACTS_TENANT_NUMBER ON contracts (tenant_id, number)');
        $this->addSql('CREATE INDEX idx_contracts_tenant_status ON contracts (tenant_id, status)');
        $this->addSql('CREATE INDEX idx_contracts_supplier ON contracts (supplier_id)');
        $this->addSql('CREATE INDEX idx_contracts_customer ON contracts (customer_id)');
        $this->addSql('ALTER TABLE contracts ADD CONSTRAINT FK_CONTRACTS_CONTRACT_TYPE FOREIGN KEY (contract_type_id) REFERENCES contract_types (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE contracts');
    }
}
