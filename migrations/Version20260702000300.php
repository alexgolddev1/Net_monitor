<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-device comment field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD comment LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP comment');
    }
}
