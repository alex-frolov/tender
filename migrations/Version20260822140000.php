<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Индексы подстрочного поиска каталога.
 *
 * Фильтр `q` разворачивается в `LOWER(number|title|description) LIKE '%…%'`,
 * фильтр `region` — в `LOWER(region) LIKE '%…%'`. Ведущий `%` делает btree
 * бесполезным: при непустом `q` каталог читался целиком.
 *
 * Из двух вариантов аудита (tsvector+GIN либо pg_trgm) взят pg_trgm: GIN по
 * триграммам обслуживает ровно то выражение, которое уже строит
 * TenderRepository::listCatalogPage, и семантика поиска не меняется —
 * подстрока остаётся подстрокой. Полнотекст с plainto_tsquery искал бы по
 * словам со стеммингом и сломал бы поиск по фрагменту номера процедуры
 * («0123» внутри «TN-0123-2026»), который для каталога закупок основной.
 * Ранжирование по релевантности, если оно понадобится, добавляется поверх
 * отдельной задачей — тогда tsvector-колонка станет оправданной.
 *
 * Индексы — по ВЫРАЖЕНИЮ `LOWER(col)`: планировщик берёт индекс только при
 * совпадении с выражением запроса. Правок кода запроса не требуется.
 */
final class Version20260822140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trigram (pg_trgm) GIN indexes for catalog substring search: number, title, description, region';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tenders_number_trgm ON tenders USING GIN (LOWER(number) gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tenders_title_trgm ON tenders USING GIN (LOWER(title) gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tenders_description_trgm ON tenders USING GIN (LOWER(description) gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tenders_region_trgm ON tenders USING GIN (LOWER(region) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_number_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_title_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_description_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_tenders_region_trgm');
        // Расширение не удаляем: им могут пользоваться другие объекты БД.
    }
}
