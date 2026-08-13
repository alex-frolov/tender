<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Колонка paused_remaining_sec в auctions (T20/T21, FR-1.3.7, UC-15).
 *
 * При паузе (TRADE → PAUSED) таймер торгов замораживается: остаток
 * (planned_end_at − paused_at) сохраняется в БД (источник истины) и
 * переживает сбой Redis. При возобновлении (PAUSED → TRADE) новый
 * planned_end_at = resume_time + paused_remaining_sec.
 */
final class Version20260811130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add paused_remaining_sec to auctions (T20/T21, FR-1.3.7, UC-15)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auctions ADD paused_remaining_sec INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auctions DROP paused_remaining_sec');
    }
}
