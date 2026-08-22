<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Партиционирование auction_bids по месяцам.
 *
 * История ставок append-only и при целевом масштабе — 5 млн строк. RANGE по
 * placed_at (в этой таблице момент ставки и есть время создания строки),
 * партиция на месяц: retention/архив превращаются в DETACH одной партиции,
 * а выборки за период отсекают лишние месяцы.
 *
 * Ключ партиционирования обязан входить в КАЖДЫЙ уникальный индекс, поэтому:
 *   - PK: (id) → (id, placed_at);
 *   - uniq_auction_bids_auction_bidder_round: + placed_at;
 *   - uniq_auction_bids_auction_idem: + placed_at.
 *
 * ЧТО ЭТО ЗНАЧИТ ДЛЯ ИНВАРИАНТОВ. Уникальность «одна ставка участника на ход»
 * и уникальность idempotency-ключа теперь гарантируются в пределах партиции,
 * а не глобально. Практически это тот же инвариант: обе пары ключей начинаются
 * с auction_id, а торги одного аукциона — это минуты, и обе ставки одного хода
 * (как и повторная доставка одного запроса) неизбежно попадают в один месяц,
 * то есть в одну партицию. Разойтись по разным партициям они могли бы только
 * если бы один ход аукциона пересекал границу месяца. Гонку повторной доставки
 * (Idempotency-Key) индекс продолжает ловить: конкурирующие вставки разделены
 * миллисекундами.
 *
 * DEFAULT-партиция обязательна: без неё INSERT в месяц без партиции падает, а
 * это отказ приёма ставки в живых торгах. Заранее нарезанные месяцы (+12) и
 * `db:partitions:ensure` из планировщика держат её пустой.
 *
 * FK auction_id → auctions(id) сохраняется: внешний ключ ИЗ партиционированной
 * таблицы PostgreSQL поддерживает (обратное — ссылка НА партиционированную
 * таблицу — потребовало бы уникальности по одному id, что несовместимо с
 * партиционированием; на auction_bids не ссылается никто, winner_bid_id в
 * auctions — скалярная колонка без FK).
 */
final class Version20260822180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partition auction_bids by month (RANGE on placed_at) with a DEFAULT catch-all partition';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auction_bids RENAME TO auction_bids_legacy');

        $this->addSql(<<<'SQL'
            CREATE TABLE auction_bids (
                id                  UUID NOT NULL,
                auction_id          UUID NOT NULL,
                bidder_id           UUID NOT NULL,
                round               INT NOT NULL,
                price_minor         BIGINT NOT NULL,
                price_display_minor BIGINT NOT NULL,
                price_basis         VARCHAR(10) NOT NULL,
                vat_rate_bps        INT NOT NULL,
                is_first_price      BOOLEAN DEFAULT false NOT NULL,
                rounding_log        JSON DEFAULT NULL,
                placed_at           TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                status              VARCHAR(20) DEFAULT 'accepted' NOT NULL,
                reason              TEXT DEFAULT NULL,
                idempotency_key     VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id, placed_at)
            ) PARTITION BY RANGE (placed_at)
            SQL);

        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                month_start DATE := date_trunc('month', COALESCE((SELECT min(placed_at) FROM auction_bids_legacy), now()))::date;
                horizon     DATE := (date_trunc('month', now()) + interval '12 months')::date;
            BEGIN
                WHILE month_start < horizon LOOP
                    EXECUTE format(
                        'CREATE TABLE IF NOT EXISTS %I PARTITION OF auction_bids FOR VALUES FROM (%L) TO (%L)',
                        'auction_bids_' || to_char(month_start, 'YYYY_MM'),
                        month_start,
                        (month_start + interval '1 month')::date
                    );
                    month_start := (month_start + interval '1 month')::date;
                END LOOP;
            END
            $$
            SQL);

        $this->addSql('CREATE TABLE auction_bids_default PARTITION OF auction_bids DEFAULT');

        $this->addSql(<<<'SQL'
            INSERT INTO auction_bids (id, auction_id, bidder_id, round, price_minor, price_display_minor,
                                      price_basis, vat_rate_bps, is_first_price, rounding_log, placed_at,
                                      status, reason, idempotency_key)
            SELECT id, auction_id, bidder_id, round, price_minor, price_display_minor,
                   price_basis, vat_rate_bps, is_first_price, rounding_log, placed_at,
                   status, reason, idempotency_key
            FROM auction_bids_legacy
            SQL);

        $this->addSql('DROP TABLE auction_bids_legacy');

        $this->addSql('CREATE UNIQUE INDEX uniq_auction_bids_auction_bidder_round ON auction_bids (auction_id, bidder_id, round, placed_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_auction_bids_auction_idem ON auction_bids (auction_id, idempotency_key, placed_at)');
        $this->addSql('CREATE INDEX idx_auction_bids_auction_round ON auction_bids (auction_id, round)');
        $this->addSql('CREATE INDEX idx_auction_bids_auction_price ON auction_bids (auction_id, price_minor)');
        $this->addSql('CREATE INDEX idx_auction_bids_bidder_auction ON auction_bids (bidder_id, auction_id)');
        // Индекс п. 2 аудита (Version20260822130000) — пересоздаётся на новой таблице.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_auction_bids_auction_placed
                ON auction_bids (auction_id, placed_at DESC, round DESC)
                WHERE status = 'accepted'
            SQL);

        $this->addSql('ALTER TABLE auction_bids ADD CONSTRAINT fk_auction_bids_auction FOREIGN KEY (auction_id) REFERENCES auctions (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auction_bids RENAME TO auction_bids_partitioned');

        $this->addSql(<<<'SQL'
            CREATE TABLE auction_bids (
                id                  UUID NOT NULL,
                auction_id          UUID NOT NULL,
                bidder_id           UUID NOT NULL,
                round               INT NOT NULL,
                price_minor         BIGINT NOT NULL,
                price_display_minor BIGINT NOT NULL,
                price_basis         VARCHAR(10) NOT NULL,
                vat_rate_bps        INT NOT NULL,
                is_first_price      BOOLEAN DEFAULT false NOT NULL,
                rounding_log        JSON DEFAULT NULL,
                placed_at           TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                status              VARCHAR(20) DEFAULT 'accepted' NOT NULL,
                reason              TEXT DEFAULT NULL,
                idempotency_key     VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO auction_bids (id, auction_id, bidder_id, round, price_minor, price_display_minor,
                                      price_basis, vat_rate_bps, is_first_price, rounding_log, placed_at,
                                      status, reason, idempotency_key)
            SELECT id, auction_id, bidder_id, round, price_minor, price_display_minor,
                   price_basis, vat_rate_bps, is_first_price, rounding_log, placed_at,
                   status, reason, idempotency_key
            FROM auction_bids_partitioned
            SQL);

        $this->addSql('DROP TABLE auction_bids_partitioned');

        $this->addSql('CREATE UNIQUE INDEX uniq_auction_bids_auction_bidder_round ON auction_bids (auction_id, bidder_id, round)');
        $this->addSql('CREATE UNIQUE INDEX uniq_auction_bids_auction_idem ON auction_bids (auction_id, idempotency_key)');
        $this->addSql('CREATE INDEX idx_auction_bids_auction_round ON auction_bids (auction_id, round)');
        $this->addSql('CREATE INDEX idx_auction_bids_auction_price ON auction_bids (auction_id, price_minor)');
        $this->addSql('CREATE INDEX idx_auction_bids_bidder_auction ON auction_bids (bidder_id, auction_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_auction_bids_auction_placed
                ON auction_bids (auction_id, placed_at DESC, round DESC)
                WHERE status = 'accepted'
            SQL);

        $this->addSql('ALTER TABLE auction_bids ADD CONSTRAINT fk_auction_bids_auction FOREIGN KEY (auction_id) REFERENCES auctions (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
