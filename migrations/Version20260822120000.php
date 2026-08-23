<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Опция «заявка на участие»: tenders.bids_required (FR-1.2.1).
 *
 * Заказчик выбирает при создании тендера, нужна ли заявка на участие. true —
 * прежний порядок: приём заявок (accepting_bids), вскрытие на bids_end, допуск,
 * и только допущенный участник торгуется. false — заявок нет вовсе: фазы
 * accepting_bids у такого тендера не существует (published → bidding напрямую
 * по таймлайну), торговаться может любой, кому тендер доступен.
 *
 * DEFAULT true — существующие тендеры сохраняют текущее поведение.
 */
final class Version20260822120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bids_required (participation bid required) to tenders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenders ADD COLUMN IF NOT EXISTS bids_required BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenders DROP COLUMN IF EXISTS bids_required');
    }
}
