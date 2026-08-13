<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Фаза 5 (5.4–5.9): исполнение договоров.
 *
 * - contract_tenders — связь договор ↔ тендер (multi_use, FR-1.4.6, M4):
 *   цена/условия по тендеру, статус исполнения по тендеру
 *   (pending/in_work/done_by_performer/done/claim/done_by_claim/terminated);
 * - claims — претензии заказчика (FR-1.4.5): сумма (копейки), основание,
 *   стадия, статус (draft/submitted/resolved_rejected/resolved_accepted/cancelled);
 * - contract_documents — скан договора (многоразовый, FR-1.4.7, UC-08a);
 * - securities — обеспечение заявок/контрактов (FR-1.4.1/1.4.2, B5):
 *   kind (bid/contract), calculation_basis (nmck/first_bid), статус;
 * - contract_stages — этапы исполнения по тендеру (FR-1.4.3, UC-10).
 *
 * Деньги — bigint minor units (PR-1). Все таблицы — в рамках tenant
 * (компания-заказчик).
 */
final class Version20260811150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Phase 5 execution tables: contract_tenders, claims, contract_documents, securities, contract_stages';
    }

    public function up(Schema $schema): void
    {
        // --- contract_tenders (FR-1.4.6, M4) ---
        $this->addSql("CREATE TABLE contract_tenders (id UUID NOT NULL, contract_id UUID NOT NULL, tender_id UUID NOT NULL, lot_id UUID DEFAULT NULL, award_id UUID DEFAULT NULL, price_net_minor BIGINT NOT NULL, price_gross_minor BIGINT NOT NULL, vat_rate_bps INT NOT NULL, status VARCHAR(20) DEFAULT 'pending' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_contract_tenders_contract_tender ON contract_tenders (contract_id, tender_id, lot_id)');
        $this->addSql('CREATE INDEX idx_contract_tenders_tender ON contract_tenders (tender_id)');
        $this->addSql('CREATE INDEX idx_contract_tenders_contract ON contract_tenders (contract_id)');
        $this->addSql('ALTER TABLE contract_tenders ADD CONSTRAINT FK_CT_CONTRACT FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- claims (FR-1.4.5) ---
        $this->addSql("CREATE TABLE claims (id UUID NOT NULL, tenant_id UUID NOT NULL, contract_id UUID NOT NULL, auction_id UUID DEFAULT NULL, supplier_id UUID NOT NULL, customer_id UUID NOT NULL, stage VARCHAR(20) NOT NULL, reason VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, amount_minor BIGINT NOT NULL, status VARCHAR(30) DEFAULT 'draft' NOT NULL, resolution TEXT DEFAULT NULL, resolved_by UUID DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, documents_refs JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_claims_contract ON claims (contract_id)');
        $this->addSql('CREATE INDEX idx_claims_tenant_status ON claims (tenant_id, status)');

        // --- contract_documents (FR-1.4.7, UC-08a) ---
        $this->addSql('CREATE TABLE contract_documents (id UUID NOT NULL, contract_id UUID NOT NULL, document_id UUID NOT NULL, uploaded_by VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_contract_documents_contract_doc ON contract_documents (contract_id, document_id)');
        $this->addSql('CREATE INDEX idx_contract_documents_contract ON contract_documents (contract_id)');

        // --- securities (FR-1.4.1/1.4.2, B5) ---
        $this->addSql("CREATE TABLE securities (id UUID NOT NULL, tenant_id UUID NOT NULL, kind VARCHAR(10) NOT NULL, entity_type VARCHAR(20) NOT NULL, entity_id UUID NOT NULL, supplier_id UUID NOT NULL, type VARCHAR(20) NOT NULL, amount_minor BIGINT NOT NULL, calculation_basis VARCHAR(10) NOT NULL, basis_amount_minor BIGINT DEFAULT NULL, currency VARCHAR(3) DEFAULT 'RUB' NOT NULL, status VARCHAR(20) DEFAULT 'active' NOT NULL, valid_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, external_ref VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_securities_supplier ON securities (supplier_id)');
        $this->addSql('CREATE INDEX idx_securities_entity ON securities (entity_type, entity_id)');

        // --- contract_stages (FR-1.4.3, UC-10) ---
        $this->addSql("CREATE TABLE contract_stages (id UUID NOT NULL, contract_tender_id UUID NOT NULL, number INT NOT NULL, title VARCHAR(300) NOT NULL, amount_minor BIGINT DEFAULT NULL, due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(30) DEFAULT 'pending' NOT NULL, acceptance_docs_refs JSON DEFAULT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_contract_stages_tender ON contract_stages (contract_tender_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE contract_stages');
        $this->addSql('DROP TABLE securities');
        $this->addSql('DROP TABLE contract_documents');
        $this->addSql('DROP TABLE claims');
        $this->addSql('ALTER TABLE contract_tenders DROP CONSTRAINT FK_CT_CONTRACT');
        $this->addSql('DROP TABLE contract_tenders');
    }
}
