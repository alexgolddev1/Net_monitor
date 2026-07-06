<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store NetFlow v9 post-NAT IPv4 fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_flow ADD post_nat_src_ip VARCHAR(45) DEFAULT NULL, ADD post_nat_dst_ip VARCHAR(45) DEFAULT NULL, ADD INDEX idx_network_flow_post_nat_src_ip (post_nat_src_ip), ADD INDEX idx_network_flow_post_nat_dst_ip (post_nat_dst_ip)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_flow DROP INDEX idx_network_flow_post_nat_src_ip, DROP INDEX idx_network_flow_post_nat_dst_ip, DROP post_nat_src_ip, DROP post_nat_dst_ip');
    }
}
