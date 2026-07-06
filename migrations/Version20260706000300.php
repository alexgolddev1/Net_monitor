<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dashboard-friendly network_flow indexes for day-range aggregations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_network_flow_received_device ON network_flow (received_at, device_id)');
        $this->addSql('CREATE INDEX idx_network_flow_received_client ON network_flow (received_at, client_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_network_flow_received_device ON network_flow');
        $this->addSql('DROP INDEX idx_network_flow_received_client ON network_flow');
    }
}
