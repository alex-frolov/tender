<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица auctions (data-model.md 2.6, FR-1.3.1/1.3.8, AM-5).
 *
 * Аукционы — один на лот тендера (unique tender_id+lot_id). Параметры торгов
 * (шаг, таймер, границы, trade_end_lead_hours) и статус (16 статусов через
 * symfony/workflow, config/workflow/auction.yaml — задача 4.2). rules_snapshot
 * (PR-9) — срез правил плагина (AuctionRules) + параметров аукциона,
 * фиксируемый при входе в TRADE (Auction::captureRulesSnapshot) и не меняющийся
 * в ходе торгов. Деньги — bigint minor units (PR-1); проценты — int bps (×10000).
 * Source of truth — PostgreSQL; live-состояние (current_price, таймер) — Redis.
 */
final class Version20260811100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create auctions table (data-model 2.6, FR-1.3, rules_snapshot)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE auctions (id UUID NOT NULL, tenant_id UUID NOT NULL, tender_id UUID NOT NULL, lot_id UUID NOT NULL, type VARCHAR(20) NOT NULL, step_mode VARCHAR(10) DEFAULT 'fixed' NOT NULL, no_start_price BOOLEAN DEFAULT false NOT NULL, status VARCHAR(20) DEFAULT 'new' NOT NULL, scheduled_start_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, paused_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, start_price_minor BIGINT DEFAULT NULL, current_price_minor BIGINT DEFAULT NULL, bid_step_minor BIGINT DEFAULT NULL, bid_step_percent_bps INT DEFAULT NULL, price_min_limit_minor BIGINT DEFAULT NULL, price_max_limit_minor BIGINT DEFAULT NULL, trade_end_lead_hours INT DEFAULT 0 NOT NULL, price_basis VARCHAR(10) NOT NULL, vat_rate_bps INT NOT NULL, step_duration_sec INT NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, planned_end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, actual_end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, extensions_count INT DEFAULT 0 NOT NULL, max_extensions INT NOT NULL, rules_snapshot JSON DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_auctions_tender_lot ON auctions (tender_id, lot_id)');
        $this->addSql('CREATE INDEX idx_auctions_tender ON auctions (tender_id)');
        $this->addSql('CREATE INDEX idx_auctions_lot ON auctions (lot_id)');
        $this->addSql('CREATE INDEX idx_auctions_tenant_status ON auctions (tenant_id, status)');
        $this->addSql('ALTER TABLE auctions ADD CONSTRAINT FK_AUCTIONS_TENDER FOREIGN KEY (tender_id) REFERENCES tenders (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE auctions ADD CONSTRAINT FK_AUCTIONS_LOT FOREIGN KEY (lot_id) REFERENCES lots (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auctions');
    }
}
