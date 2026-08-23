<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Материализация агрегированного статуса тендера: tenders.aggregated_status.
 *
 * Дашборд и статистика считали агрегат на лету: LEFT JOIN lots + STRING_AGG +
 * GROUP BY по ВСЕМ тендерам компании, без LIMIT — на каждое открытие
 * /dashboard и /stats/tenders. Для заказчика с тысячами процедур это тысячи
 * групп и десятки тысяч строк лотов за запрос.
 *
 * Колонка хранит ровно то, что возвращает Tender::aggregatedStatus() (единый
 * источник истины — Tender::aggregateStatus()); актуальность поддерживает
 * Tender::refreshAggregatedStatus() из сеттеров marking_store (Tender::setStatus,
 * Lot::setStatus — через них проходит любой переход workflow) и из
 * addLot()/removeLot(). JOIN уходит из read-пути совсем, а фильтрация и
 * сортировка каталога по агрегированному статусу становятся возможны на
 * стороне БД.
 *
 * Бэкфилл повторяет вариант C ровно как в PHP:
 *   1. статус = фаза самого раннего НЕзавершённого лота (min-phase);
 *   2. фазы draft(0)/published(1) административные — берётся статус тендера;
 *   3. все лоты терминальны: все cancelled → cancelled, иначе closed;
 *   4. лотов нет — статус тендера.
 * Терминальные лоты выпадают из min() сами: в CASE у них нет ветки, значит
 * NULL, а min() игнорирует NULL — это и есть «пропустить завершённые».
 */
final class Version20260822160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Materialize tenders.aggregated_status (variant C aggregation) with a backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenders ADD COLUMN IF NOT EXISTS aggregated_status VARCHAR(20) DEFAULT 'draft' NOT NULL");

        // База: тендеры без лотов (и административные фазы) — статус тендера.
        $this->addSql('UPDATE tenders SET aggregated_status = status');

        $this->addSql(<<<'SQL'
            WITH agg AS (
                SELECT l.tender_id,
                       min(CASE l.status
                               WHEN 'draft'          THEN 0
                               WHEN 'published'      THEN 1
                               WHEN 'accepting_bids' THEN 2
                               WHEN 'bidding'        THEN 3
                               WHEN 'evaluation'     THEN 4
                               WHEN 'awarding'       THEN 5
                               WHEN 'contract'       THEN 6
                           END) AS min_phase,
                       bool_and(l.status = 'cancelled') AS all_cancelled
                FROM lots l
                GROUP BY l.tender_id
            )
            UPDATE tenders t
            SET aggregated_status = CASE
                    WHEN agg.min_phase IS NULL  THEN CASE WHEN agg.all_cancelled THEN 'cancelled' ELSE 'closed' END
                    WHEN agg.min_phase <= 1     THEN t.status
                    WHEN agg.min_phase = 2      THEN 'accepting_bids'
                    WHEN agg.min_phase = 3      THEN 'bidding'
                    WHEN agg.min_phase = 4      THEN 'evaluation'
                    WHEN agg.min_phase = 5      THEN 'awarding'
                    ELSE 'contract'
                END
            FROM agg
            WHERE agg.tender_id = t.id
            SQL);

        // Счётчик дашборда группирует тендеры компании по агрегату.
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tenders_tenant_aggregated ON tenders (tenant_id, aggregated_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_tenant_aggregated');
        $this->addSql('ALTER TABLE tenders DROP COLUMN IF EXISTS aggregated_status');
    }
}
