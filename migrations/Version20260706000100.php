<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dashboard query indexes for network_flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_network_flow_received_direction ON network_flow (received_at, direction)');
        $this->addSql('CREATE INDEX idx_network_flow_received_app ON network_flow (received_at, app_name)');
        $this->addSql('CREATE INDEX idx_network_flow_received_domain ON network_flow (received_at, domain)');
        $this->addSql('CREATE INDEX idx_network_flow_device_direction_received ON network_flow (device_id, direction, received_at)');
        $this->addSql('CREATE INDEX idx_network_flow_client_direction_received ON network_flow (client_id, direction, received_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_network_flow_received_direction ON network_flow');
        $this->addSql('DROP INDEX idx_network_flow_received_app ON network_flow');
        $this->addSql('DROP INDEX idx_network_flow_received_domain ON network_flow');
        $this->addSql('DROP INDEX idx_network_flow_device_direction_received ON network_flow');
        $this->addSql('DROP INDEX idx_network_flow_client_direction_received ON network_flow');
    }
}
