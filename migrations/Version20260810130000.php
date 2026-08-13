<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблицы bids и bid_documents (FR-1.2.1/1.2.2, AM-4).
 *
 * bids — заявки участников: метаданные в открытых колонках (id, tender_id,
 * lot_id, supplier_id, status, submitted_at, decision_reason), содержимое
 * (part1/part2_ref/price) — ТОЛЬКО зашифрованным в encrypted_payload (BYTEA,
 * FR-1.2.2 «хранение зашифрованное», содержимое невидимо до вскрытия).
 * unique (tender_id, lot_id, supplier_id) — одна заявка на лот (data-model).
 *
 * bid_documents — документы заявки с номером части (1/2, двухчастность) и
 * признаком участия в секретности до вскрытия (is_encrypted, FR-1.2.2).
 */
final class Version20260810130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bids and bid_documents tables (FR-1.2.1/1.2.2, AM-4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bids (id UUID NOT NULL, tenant_id UUID NOT NULL, tender_id UUID NOT NULL, lot_id UUID DEFAULT NULL, supplier_id UUID NOT NULL, status VARCHAR(20) DEFAULT \'draft\' NOT NULL, encrypted_payload BYTEA NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, evaluated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decision_reason TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_bids_tender_lot_supplier ON bids (tender_id, lot_id, supplier_id)');
        $this->addSql('CREATE INDEX idx_bids_tender_status ON bids (tender_id, status)');
        $this->addSql('ALTER TABLE bids ADD CONSTRAINT FK_BIDS_TENDER FOREIGN KEY (tender_id) REFERENCES tenders (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE bids ADD CONSTRAINT FK_BIDS_LOT FOREIGN KEY (lot_id) REFERENCES lots (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE bid_documents (id UUID NOT NULL, bid_id UUID NOT NULL, document_id UUID NOT NULL, part INT NOT NULL, is_encrypted BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_bid_documents_bid ON bid_documents (bid_id)');
        $this->addSql('ALTER TABLE bid_documents ADD CONSTRAINT FK_BID_DOCUMENTS_BID FOREIGN KEY (bid_id) REFERENCES bids (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE bid_documents ADD CONSTRAINT FK_BID_DOCUMENTS_DOCUMENT FOREIGN KEY (document_id) REFERENCES documents (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bid_documents');
        $this->addSql('DROP TABLE bids');
    }
}
