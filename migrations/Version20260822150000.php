<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Расширение pg_stat_statements.
 *
 * Первая рекомендация аудита: без pg_stat_statements ранжировать запросы
 * по факту нечем — аудит остаётся рассуждением, а не измерением. Расширение
 * даёт учёт по нормализованным запросам (calls/total_exec_time/rows), из
 * которого postgres-exporter собирает tender_slow_* (docker/prometheus/
 * pg-queries.yaml) вместо аппроксимации по pg_stat_activity.
 *
 * Библиотеку обязан подгрузить сервер (`shared_preload_libraries`) — это
 * сделано в docker-compose.yml / docker-compose.prod.yml (`command: postgres
 * -c shared_preload_libraries=pg_stat_statements ...`). Если сервер запущен
 * без неё, CREATE EXTENSION падает — поэтому он обёрнут в DO/EXCEPTION:
 * миграция не должна ронять прогон там, где расширение недоступно (CI,
 * managed-PG без прав суперпользователя). Отсутствие расширения — потеря
 * наблюдаемости, а не поломка приложения: код к нему не обращается.
 */
final class Version20260822150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable pg_stat_statements extension (best effort) for query-level observability';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
            EXCEPTION WHEN OTHERS THEN
                RAISE WARNING 'pg_stat_statements not enabled: % (check shared_preload_libraries)', SQLERRM;
            END
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS pg_stat_statements');
    }
}
