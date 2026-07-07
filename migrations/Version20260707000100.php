<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260707000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public device profile change requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE device_profile_change_request (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, full_name VARCHAR(180) DEFAULT NULL, room_number VARCHAR(64) DEFAULT NULL, phone VARCHAR(64) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, requester_ip VARCHAR(45) DEFAULT NULL, review_note LONGTEXT DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_4D4A4D0D9395C3F3 (device_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE device_profile_change_request ADD CONSTRAINT FK_4D4A4D0D9395C3F3 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device_profile_change_request DROP FOREIGN KEY FK_4D4A4D0D9395C3F3');
        $this->addSql('DROP TABLE device_profile_change_request');
    }
}
