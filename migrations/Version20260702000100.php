<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add raw NetFlow v9 network_flow table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE network_flow (id INT AUTO_INCREMENT NOT NULL, device_id INT DEFAULT NULL, client_id INT DEFAULT NULL, exporter_ip VARCHAR(45) NOT NULL, src_ip VARCHAR(45) DEFAULT NULL, dst_ip VARCHAR(45) DEFAULT NULL, src_port INT DEFAULT NULL, dst_port INT DEFAULT NULL, protocol INT DEFAULT NULL, bytes BIGINT DEFAULT NULL, packets BIGINT DEFAULT NULL, input_interface INT DEFAULT NULL, output_interface INT DEFAULT NULL, first_seen_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_seen_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', received_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', direction VARCHAR(16) NOT NULL, INDEX IDX_61D11C4394A4C7D4 (device_id), INDEX IDX_61D11C4319EB6921 (client_id), INDEX idx_network_flow_received_at (received_at), INDEX idx_network_flow_first_seen_at (first_seen_at), INDEX idx_network_flow_device_received (device_id, received_at), INDEX idx_network_flow_client_received (client_id, received_at), INDEX idx_network_flow_src_ip (src_ip), INDEX idx_network_flow_dst_ip (dst_ip), INDEX idx_network_flow_direction (direction), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE network_flow ADD CONSTRAINT FK_61D11C4394A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE network_flow ADD CONSTRAINT FK_61D11C4319EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE network_flow');
    }
}
