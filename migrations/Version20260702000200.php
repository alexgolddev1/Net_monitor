<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DNS cache table and enrich network_flow with domain/app metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dns_cache_record (id INT AUTO_INCREMENT NOT NULL, domain VARCHAR(255) NOT NULL, record_type VARCHAR(16) NOT NULL, resolved_ip VARCHAR(45) DEFAULT NULL, cname VARCHAR(255) DEFAULT NULL, ttl INT DEFAULT NULL, first_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', source VARCHAR(32) NOT NULL DEFAULT \'mikrotik_cache\', INDEX idx_dns_cache_record_resolved_ip (resolved_ip), INDEX idx_dns_cache_record_domain (domain), INDEX idx_dns_cache_record_last_seen_at (last_seen_at), UNIQUE INDEX uniq_dns_cache_record_signature (domain, record_type, resolved_ip, cname), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE network_flow ADD domain VARCHAR(255) DEFAULT NULL, ADD app_name VARCHAR(128) DEFAULT NULL, ADD organization VARCHAR(255) DEFAULT NULL, ADD domain_source VARCHAR(32) DEFAULT NULL, ADD INDEX idx_network_flow_domain (domain), ADD INDEX idx_network_flow_app_name (app_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE network_flow DROP domain, DROP app_name, DROP organization, DROP domain_source');
        $this->addSql('DROP TABLE dns_cache_record');
    }
}
