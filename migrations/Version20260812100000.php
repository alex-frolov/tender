<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Фаза 6 (6.1): webhooks (WH-1..7).
 *
 * - webhooks — подписки компании-тенанта: url подписчика, секрет HMAC-SHA256
 *   (WH-3), список типов событий (WH-1), фильтры payload (WH-7),
 *   статус active/paused;
 * - webhook_deliveries — журнал доставок (WH-2..6): payload (канонический JSON
 *   тела, подписанный HMAC), event_id (WH-4), попытки/ошибка/next_retry
 *   (backoff WH-5), dead-letter. unique (webhook_id, event_id) — идемпотентность
 *   пересоздания при повторной доставке события (at-least-once).
 *
 * Секрет хранится в БД (нужен для подписи на доставке); в API-ответах
 * отдаётся один раз (создание/ротация).
 */
final class Version20260812100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create webhook subscriptions and delivery log (WH-1..7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE webhooks (id UUID NOT NULL, tenant_id UUID NOT NULL, url VARCHAR(2048) NOT NULL, secret VARCHAR(128) NOT NULL, events JSON NOT NULL, filters JSON DEFAULT NULL, status VARCHAR(10) DEFAULT 'active' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_webhooks_tenant_status ON webhooks (tenant_id, status)');

        $this->addSql("CREATE TABLE webhook_deliveries (id UUID NOT NULL, webhook_id UUID NOT NULL, event_id UUID NOT NULL, event_type VARCHAR(50) NOT NULL, payload TEXT NOT NULL, status VARCHAR(10) DEFAULT 'pending' NOT NULL, attempts INT DEFAULT 0 NOT NULL, next_retry_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_http_status INT DEFAULT NULL, last_error TEXT DEFAULT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_webhook_deliveries_webhook_event ON webhook_deliveries (webhook_id, event_id)');
        $this->addSql('CREATE INDEX idx_webhook_deliveries_webhook_status ON webhook_deliveries (webhook_id, status)');
        $this->addSql('CREATE INDEX idx_webhook_deliveries_event ON webhook_deliveries (event_id)');
        $this->addSql('ALTER TABLE webhook_deliveries ADD CONSTRAINT FK_WH_DELIVERY_WEBHOOK FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_deliveries DROP CONSTRAINT FK_WH_DELIVERY_WEBHOOK');
        $this->addSql('DROP TABLE webhook_deliveries');
        $this->addSql('DROP TABLE webhooks');
    }
}
