<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial network monitoring MVP schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(180) DEFAULT NULL, room_number VARCHAR(64) DEFAULT NULL, phone VARCHAR(64) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE device (id INT AUTO_INCREMENT NOT NULL, client_id INT DEFAULT NULL, mac VARCHAR(17) NOT NULL, current_ip VARCHAR(45) DEFAULT NULL, hostname VARCHAR(180) DEFAULT NULL, vendor VARCHAR(180) DEFAULT NULL, vlan VARCHAR(64) DEFAULT NULL, device_name VARCHAR(180) DEFAULT NULL, first_seen_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_seen_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_device_mac (mac), INDEX IDX_92FB68E19EB6921 (client_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE device_ip_history (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, ip VARCHAR(45) NOT NULL, first_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_310399E194A4C7D4 (device_id), INDEX idx_ip_history_ip (ip), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE traffic_snapshot (id INT AUTO_INCREMENT NOT NULL, device_id INT DEFAULT NULL, mac VARCHAR(17) DEFAULT NULL, ip VARCHAR(45) NOT NULL, bytes_in BIGINT NOT NULL, bytes_out BIGINT NOT NULL, total_bytes BIGINT NOT NULL, packets_in INT DEFAULT NULL, packets_out INT DEFAULT NULL, apps_json JSON DEFAULT NULL, destinations_json JSON DEFAULT NULL, snapshot_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_2B5F6F5394A4C7D4 (device_id), INDEX idx_snapshot_ip (ip), INDEX idx_snapshot_at (snapshot_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE device_daily_usage (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', bytes_in BIGINT NOT NULL, bytes_out BIGINT NOT NULL, total_bytes BIGINT NOT NULL, top_apps_json JSON DEFAULT NULL, top_destinations_json JSON DEFAULT NULL, INDEX IDX_EF56CE4394A4C7D4 (device_id), UNIQUE INDEX uniq_device_usage_date (device_id, date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE site_catalog (id INT AUTO_INCREMENT NOT NULL, domain VARCHAR(255) NOT NULL, title VARCHAR(255) DEFAULT NULL, category VARCHAR(128) DEFAULT NULL, logo_url VARCHAR(512) DEFAULT NULL, favicon_url VARCHAR(512) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_site_domain (domain), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE device ADD CONSTRAINT FK_92FB68E19EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE device_ip_history ADD CONSTRAINT FK_310399E194A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE traffic_snapshot ADD CONSTRAINT FK_2B5F6F5394A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE device_daily_usage ADD CONSTRAINT FK_EF56CE4394A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE device_daily_usage');
        $this->addSql('DROP TABLE device_ip_history');
        $this->addSql('DROP TABLE traffic_snapshot');
        $this->addSql('DROP TABLE site_catalog');
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE client');
    }
}
