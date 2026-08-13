<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Колонка winner_bid_id в auctions (FR-1.3.5, задача 4.9).
 *
 * Фиксирует победителя аукциона (id победившей ставки auction_bids.id):
 * для REDUCTION выбирается автоматически (минимальная цена) при APPROVE;
 * для FREE_PRICE/PRICE_REQUEST — заказчиком в CHOICE (UC-13a). Значение
 * участвует в событиях auction.finished / auction.winner_chosen
 * (domain/events.md) и в карточке аукциона.
 */
final class Version20260811140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add winner_bid_id to auctions (FR-1.3.5, winner selection)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auctions ADD winner_bid_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auctions DROP winner_bid_id');
    }
}
