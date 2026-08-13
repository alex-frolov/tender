<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Вскрытие заявок (FR-1.2.3, задача 3.3).
 *
 * - bids.decrypted_payload (JSON, nullable): расшифрованное содержимое заявки
 *   (part1, part2_ref, price), заполняется на вскрытии BidOpeningService.
 *   До вскрытия null — содержимое невидимо (FR-1.2.2, хранение зашифрованное);
 *   encrypted_payload при этом не изменяется (аудит-след).
 * - tenders.bids_opened_at (timestamptz, nullable): момент автоматического
 *   вскрытия по таймлайну (bids_end); gate для read-пути (presenter): после
 *   вскрытия заявки видны заказчику и (в части) участникам.
 */
final class Version20260810140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bids.decrypted_payload and tenders.bids_opened_at (FR-1.2.3, auto-opening)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bids ADD decrypted_payload JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tenders ADD bids_opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenders DROP COLUMN bids_opened_at');
        $this->addSql('ALTER TABLE bids DROP COLUMN decrypted_payload');
    }
}
