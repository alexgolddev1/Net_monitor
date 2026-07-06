<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add incremental traffic rollup tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE traffic_aggregation_state (id INT NOT NULL, last_flow_id INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO traffic_aggregation_state (id, last_flow_id, updated_at) VALUES (1, 0, NOW())');

        $this->addSql('CREATE TABLE device_daily_app_usage (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', app_name VARCHAR(128) NOT NULL, bytes BIGINT NOT NULL, INDEX idx_device_daily_app_date (date), INDEX idx_device_daily_app_device_date (device_id, date), UNIQUE INDEX uniq_device_daily_app (device_id, date, app_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE device_daily_app_usage ADD CONSTRAINT FK_DEVICE_DAILY_APP_DEVICE FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE device_daily_domain_usage (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', domain VARCHAR(255) NOT NULL, last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', bytes BIGINT NOT NULL, INDEX idx_device_daily_domain_date (date), INDEX idx_device_daily_domain_device_date (device_id, date), INDEX idx_device_daily_domain_last_seen (last_seen_at), UNIQUE INDEX uniq_device_daily_domain (device_id, date, domain), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE device_daily_domain_usage ADD CONSTRAINT FK_DEVICE_DAILY_DOMAIN_DEVICE FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE traffic_daily_direction_usage (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', direction VARCHAR(16) NOT NULL, bytes BIGINT NOT NULL, INDEX idx_traffic_daily_direction_date (date), UNIQUE INDEX uniq_traffic_daily_direction (date, direction), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device_daily_domain_usage DROP FOREIGN KEY FK_DEVICE_DAILY_DOMAIN_DEVICE');
        $this->addSql('DROP TABLE device_daily_domain_usage');

        $this->addSql('ALTER TABLE device_daily_app_usage DROP FOREIGN KEY FK_DEVICE_DAILY_APP_DEVICE');
        $this->addSql('DROP TABLE device_daily_app_usage');

        $this->addSql('DROP TABLE traffic_daily_direction_usage');

        $this->addSql('DROP TABLE traffic_aggregation_state');
    }
}
