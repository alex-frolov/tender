<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблицы tenders и lots (FR-1.1, FR-1.1.7).
 *
 * tenders — контейнер лотов; lots — независимые лоты со своей НМЦК.
 * Каноническая цена — price_net_minor (net, PR-3); price_gross_minor —
 * производная net × (1 + vat_rate). Инвариант суммы лотов (FR-1.1.7)
 * проверяется на уровне домена (Tender::assertLotsSumInvariant()), не в БД.
 */
final class Version20260810110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenders and lots tables (FR-1.1, FR-1.1.7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tenders (id UUID NOT NULL, tenant_id UUID NOT NULL, number VARCHAR(64) NOT NULL, title VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, procedure_type VARCHAR(20) NOT NULL, law_type VARCHAR(10) NOT NULL, nmck_minor BIGINT DEFAULT NULL, no_start_price BOOLEAN DEFAULT false NOT NULL, currency VARCHAR(3) NOT NULL, vat_rate_bps INT NOT NULL, price_basis VARCHAR(10) NOT NULL, customer_id UUID NOT NULL, region VARCHAR(100) DEFAULT NULL, access_type VARCHAR(20) DEFAULT \'open\' NOT NULL, required_contract_type_id UUID DEFAULT NULL, status VARCHAR(20) DEFAULT \'draft\' NOT NULL, execution_rating INT DEFAULT NULL, cancellation_reason_code VARCHAR(40) DEFAULT NULL, cancellation_reason_text TEXT DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, timeline JSON DEFAULT NULL, security_required BOOLEAN DEFAULT false NOT NULL, national_regime JSON DEFAULT NULL, created_by UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TENDERS_TENANT_NUMBER ON tenders (tenant_id, number)');
        $this->addSql('CREATE INDEX idx_tenders_tenant_status ON tenders (tenant_id, status)');
        $this->addSql('CREATE INDEX idx_tenders_tenant_customer ON tenders (tenant_id, customer_id)');

        $this->addSql('CREATE TABLE lots (id UUID NOT NULL, tender_id UUID NOT NULL, number INT NOT NULL, title VARCHAR(500) NOT NULL, price_net_minor BIGINT NOT NULL, price_gross_minor BIGINT NOT NULL, vat_rate_bps INT NOT NULL, price_basis VARCHAR(10) NOT NULL, currency VARCHAR(3) NOT NULL, quantity DOUBLE PRECISION DEFAULT NULL, unit VARCHAR(50) DEFAULT NULL, delivery_terms JSON DEFAULT NULL, execution_start_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, trade_end_lead_hours INT DEFAULT 0 NOT NULL, security_percent DOUBLE PRECISION DEFAULT NULL, status VARCHAR(20) DEFAULT \'draft\' NOT NULL, winner_bid_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_lots_tender ON lots (tender_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_LOTS_TENDER_NUMBER ON lots (tender_id, number)');
        $this->addSql('ALTER TABLE lots ADD CONSTRAINT FK_LOTS_TENDER FOREIGN KEY (tender_id) REFERENCES tenders (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lots');
        $this->addSql('DROP TABLE tenders');
    }
}
