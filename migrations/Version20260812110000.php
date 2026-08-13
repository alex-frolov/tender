<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Фаза 6 (6.2): analytics_counters — агрегаты аналитики (ARCH-9).
 *
 * real-time счётчики живут в Redis (`ctr:{tenant}:{metric}:{date}`, INCR);
 * фоновый джоб (analytics:counters:snapshot) периодически снимает снапшот
 * Redis → upsert в эту таблицу → ротирует (сбрасывает) Redis-ключи.
 * Чтение: Redis (свежие) → PG (пересчитанные); materialized views запрещены.
 *
 * - unique (tenant_id, metric, period, dimension): счётчик на срез на период.
 *   dimension — JSONB (срез: регион/ОКПД2/заказчик/исполнитель; '{}' для
 *   без среза). Значения накапливаются аддитивно (ON CONFLICT ... DO UPDATE
 *   SET value = value + EXCLUDED.value).
 */
final class Version20260812110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create analytics counters aggregation table (ARCH-9)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE analytics_counters (id BIGSERIAL NOT NULL, tenant_id UUID NOT NULL, metric VARCHAR(40) NOT NULL, period DATE NOT NULL, dimension JSONB DEFAULT '{}'::jsonb NOT NULL, value BIGINT DEFAULT 0 NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_analytics_counters ON analytics_counters (tenant_id, metric, period, dimension)');
        $this->addSql('CREATE INDEX idx_analytics_counters_tenant_metric_period ON analytics_counters (tenant_id, metric, period)');
        $this->addSql('CREATE INDEX idx_analytics_counters_dimension ON analytics_counters (dimension)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE analytics_counters');
    }
}
