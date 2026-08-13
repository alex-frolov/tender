<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Фаза 6 (6.6): уведомления (FR-1.6).
 *
 * - notification_subscriptions — подписки пользователя на уведомления:
 *   канал (email/webhook/telegram), типы событий (domain/events.md),
 *   фильтры payload, флаг digest (собирать события в ежедневный дайджест).
 *   Подписка принадлежит пользователю (user_id) и его компании-тенанту
 *   (tenant_id — компания на момент создания);
 * - notification_digest_items — накопленные для дайджеста события (FR-1.6):
 *   уникальность (user_id, event_id) делает добавление идемпотентным при
 *   повторной доставке события (at-least-once); sent_at фиксирует отправку.
 *
 * Доставка: outbox → RabbitMQ → NotificationDeliveryService::queueEmails →
 * NotificationEmailMessage (transport `emails`) или накопление в
 * notification_digest_items → NotificationDigestService::sendDigests.
 */
final class Version20260812160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification subscriptions and digest items (FR-1.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_subscriptions (id UUID NOT NULL, user_id UUID NOT NULL, tenant_id UUID NOT NULL, channel VARCHAR(10) NOT NULL, events JSON NOT NULL, filters JSON DEFAULT NULL, digest BOOLEAN DEFAULT false NOT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notification_subscriptions_user ON notification_subscriptions (user_id)');
        $this->addSql('CREATE INDEX idx_notification_subscriptions_tenant_channel_active ON notification_subscriptions (tenant_id, channel, active)');

        $this->addSql('CREATE TABLE notification_digest_items (id UUID NOT NULL, user_id UUID NOT NULL, event_id UUID NOT NULL, event_type VARCHAR(50) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, payload JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_digest_items_user_event ON notification_digest_items (user_id, event_id)');
        $this->addSql('CREATE INDEX idx_notification_digest_items_user_sent ON notification_digest_items (user_id, sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_digest_items');
        $this->addSql('DROP TABLE notification_subscriptions');
    }
}
