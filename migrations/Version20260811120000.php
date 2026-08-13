<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Уникальный индекс (auction_id, idempotency_key) на auction_bids (ARCH-6,
 * FR-1.3.6): повторная доставка ставки (at-least-once) с тем же
 * Idempotency-Key клиента не создаёт дубль на уровне БД. Сервисный replay
 * (AuctionBidService::replayBid) — первый рубеж; индекс — защита от гонок.
 *
 * idempotency_key nullable: NULL не участвует в уникальности (обычная запись
 * без ключа), уникальны только не-NULL пары (auction_id, idempotency_key).
 */
final class Version20260811120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unique (auction_id, idempotency_key) on auction_bids (ARCH-6, FR-1.3.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_auction_bids_auction_idem ON auction_bids (auction_id, idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_auction_bids_auction_idem');
    }
}
