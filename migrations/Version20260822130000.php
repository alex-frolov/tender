<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Индексы горячих путей по аудиту запросов.
 *
 * 1. idx_bids_supplier_status (bids: supplier_id, status) — Q2. У bids не было
 *    ни одного индекса, ведущего по supplier_id: уникальный
 *    (tender_id, lot_id, supplier_id) держит поставщика третьим, и планировщик
 *    брал idx_bids_tender_status по статусам, отбирая пять статусов из шести
 *    (почти всю таблицу) и фильтруя supplier_id построчно. Запрос идёт на
 *    каждый GET /dashboard, GET /stats/tenders и на GET /tenders со стороны
 *    участника (BidRepository::tenderIdsForSupplier/countForSupplier/
 *    tenderIdsWonBy/lotIdsWonBy).
 *
 * 2. idx_auction_bids_auction_placed (auction_bids: auction_id, placed_at DESC,
 *    round DESC) WHERE status='accepted' — Q4. AuctionRepository::lastAcceptedBids
 *    берёт DISTINCT ON (auction_id) с ORDER BY placed_at DESC, round DESC на
 *    каждую страницу GET /auctions; idx_auction_bids_auction_price давал только
 *    вход по auction_id, и Postgres сортировал ВСЕ ставки каждого аукциона.
 *    Индекс отдаёт готовый порядок — сортировка уходит из плана. Частичный
 *    (только accepted): отклонённые ставки на цену не влияют (PR-9), а размер
 *    индекса меньше.
 *
 * 3. idx_tenders_okpd2_prefix (tenders: LOWER(okpd2) text_pattern_ops) — Q1.
 *    Фильтр каталога okpd2 — префиксный (`LOWER(okpd2) LIKE 'код%'`); индекса
 *    по okpd2 не было вовсе. text_pattern_ops нужен потому, что LIKE-префикс
 *    использует btree только с этим классом операторов (при не-C collation).
 *
 * Все индексы — IF NOT EXISTS (повторный apply безопасен). DESC-порядок,
 * частичность и выражение в DQL/атрибутах Doctrine не выражаются, поэтому
 * пп. 2–3 живут только в миграции (как idx_tenders_catalog_* из
 * Version20260813160000/Version20260819120000).
 */
final class Version20260822130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hot-path indexes: bids(supplier_id,status), auction_bids(auction_id,placed_at DESC), tenders(lower(okpd2))';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_bids_supplier_status ON bids (supplier_id, status)');
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_auction_bids_auction_placed
                ON auction_bids (auction_id, placed_at DESC, round DESC)
                WHERE status = 'accepted'
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_tenders_okpd2_prefix
                ON tenders (LOWER(okpd2) text_pattern_ops)
                WHERE okpd2 IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_bids_supplier_status');
        $this->addSql('DROP INDEX IF EXISTS idx_auction_bids_auction_placed');
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_okpd2_prefix');
    }
}
