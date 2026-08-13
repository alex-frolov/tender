<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Язык пользователя (locale: ru/en) — письма и интерфейс (FR-1.5.4).
 */
final class Version20260809130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.locale (ru/en)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD locale VARCHAR(5) DEFAULT \'ru\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP locale');
    }
}
