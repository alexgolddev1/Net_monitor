<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ip_intel cache and traffic analysis indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ip_intel (id INT AUTO_INCREMENT NOT NULL, ip VARCHAR(45) NOT NULL, asn INT DEFAULT NULL, organization VARCHAR(255) DEFAULT NULL, isp VARCHAR(255) DEFAULT NULL, country VARCHAR(64) DEFAULT NULL, city VARCHAR(128) DEFAULT NULL, reverse_dns VARCHAR(255) DEFAULT NULL, is_hosting TINYINT(1) DEFAULT NULL, is_proxy TINYINT(1) DEFAULT NULL, is_mobile TINYINT(1) DEFAULT NULL, abuse_score INT DEFAULT NULL, category VARCHAR(32) DEFAULT NULL, confidence INT DEFAULT NULL, source VARCHAR(255) DEFAULT NULL, checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_ip_intel_ip (ip), INDEX idx_ip_intel_checked_at (checked_at), INDEX idx_ip_intel_category (category), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE INDEX idx_network_flow_torrent_detector ON network_flow (protocol, received_at, src_ip, src_port)');
        $this->addSql('CREATE INDEX idx_network_flow_vpn_detector ON network_flow (client_id, received_at, dst_ip)');
        $this->addSql('CREATE INDEX idx_network_flow_common_service ON network_flow (dst_ip, received_at, client_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_network_flow_common_service ON network_flow');
        $this->addSql('DROP INDEX idx_network_flow_vpn_detector ON network_flow');
        $this->addSql('DROP INDEX idx_network_flow_torrent_detector ON network_flow');
        $this->addSql('DROP TABLE ip_intel');
    }
}
