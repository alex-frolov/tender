<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица auction_bids (data-model.md 2.6, FR-1.3.2/1.3.6, PR-6/PR-9, AM-5).
 *
 * Ставки аукциона — append-only: принятые и отклонённые (status + reason)
 * сохраняются в истории; отклонённые не влияют на current_price. Инвариант
 * «одна ставка на участника на ход» — unique (auction_id, bidder_id, round);
 * idempotency_key — защита от дублей повторной доставки (AR-4). Деньги —
 * bigint minor units: price_minor — каноническая база сравнения (PR-6),
 * price_display_minor — в базисе участника. Партиционирование по месяцам
 * (аукционы — высокочастотные записи) — полировка (Фаза 7), в MVP — таблица.
 */
final class Version20260811110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create auction_bids table (data-model 2.6, FR-1.3, append-only bids)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE auction_bids (id UUID NOT NULL, auction_id UUID NOT NULL, bidder_id UUID NOT NULL, round INT NOT NULL, price_minor BIGINT NOT NULL, price_display_minor BIGINT NOT NULL, price_basis VARCHAR(10) NOT NULL, vat_rate_bps INT NOT NULL, is_first_price BOOLEAN DEFAULT false NOT NULL, rounding_log JSON DEFAULT NULL, placed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(20) DEFAULT 'accepted' NOT NULL, reason TEXT DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_auction_bids_auction_bidder_round ON auction_bids (auction_id, bidder_id, round)');
        $this->addSql('CREATE INDEX idx_auction_bids_auction_round ON auction_bids (auction_id, round)');
        $this->addSql('CREATE INDEX idx_auction_bids_auction_price ON auction_bids (auction_id, price_minor)');
        $this->addSql('CREATE INDEX idx_auction_bids_bidder_auction ON auction_bids (bidder_id, auction_id)');
        $this->addSql('ALTER TABLE auction_bids ADD CONSTRAINT FK_AUCTION_BIDS_AUCTION FOREIGN KEY (auction_id) REFERENCES auctions (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auction_bids');
    }
}
