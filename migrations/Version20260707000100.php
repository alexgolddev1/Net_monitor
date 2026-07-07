<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260707000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hourly traffic graph rollup table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE traffic_hourly_usage (id INT AUTO_INCREMENT NOT NULL, bucket_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', scope_type VARCHAR(16) NOT NULL, scope_id INT NOT NULL DEFAULT 0, download_bytes BIGINT NOT NULL, upload_bytes BIGINT NOT NULL, total_bytes BIGINT NOT NULL, INDEX idx_traffic_hourly_bucket (bucket_at), INDEX idx_traffic_hourly_scope (scope_type, scope_id, bucket_at), UNIQUE INDEX uniq_traffic_hourly (bucket_at, scope_type, scope_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("INSERT INTO traffic_hourly_usage (bucket_at, scope_type, scope_id, download_bytes, upload_bytes, total_bytes)
            SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H:00:00') bucketAt,
                   'all' scopeType,
                   0 scopeId,
                   SUM(CASE WHEN direction = 'download' THEN COALESCE(bytes, 0) ELSE 0 END) downloadBytes,
                   SUM(CASE WHEN direction = 'upload' THEN COALESCE(bytes, 0) ELSE 0 END) uploadBytes,
                   SUM(COALESCE(bytes, 0)) totalBytes
            FROM network_flow
            WHERE received_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
            GROUP BY bucketAt
            ON DUPLICATE KEY UPDATE
                download_bytes = VALUES(download_bytes),
                upload_bytes = VALUES(upload_bytes),
                total_bytes = VALUES(total_bytes)");

        $this->addSql("INSERT INTO traffic_hourly_usage (bucket_at, scope_type, scope_id, download_bytes, upload_bytes, total_bytes)
            SELECT resolved.bucketAt,
                   'device' scopeType,
                   resolved.deviceId scopeId,
                   SUM(CASE WHEN resolved.direction = 'download' THEN COALESCE(resolved.bytes, 0) ELSE 0 END) downloadBytes,
                   SUM(CASE WHEN resolved.direction = 'upload' THEN COALESCE(resolved.bytes, 0) ELSE 0 END) uploadBytes,
                   SUM(COALESCE(resolved.bytes, 0)) totalBytes
            FROM (
                SELECT DATE_FORMAT(f.received_at, '%Y-%m-%d %H:00:00') bucketAt,
                       COALESCE(f.device_id, d.id) deviceId,
                       f.direction,
                       f.bytes
                FROM network_flow f
                LEFT JOIN device d ON f.device_id IS NULL AND (
                    (f.direction = 'upload' AND d.current_ip = f.src_ip)
                    OR (f.direction = 'download' AND d.current_ip = COALESCE(f.post_nat_dst_ip, f.dst_ip))
                )
                WHERE f.received_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
            ) resolved
            WHERE resolved.deviceId IS NOT NULL
            GROUP BY resolved.bucketAt, resolved.deviceId
            ON DUPLICATE KEY UPDATE
                download_bytes = VALUES(download_bytes),
                upload_bytes = VALUES(upload_bytes),
                total_bytes = VALUES(total_bytes)");

        $this->addSql("INSERT INTO traffic_hourly_usage (bucket_at, scope_type, scope_id, download_bytes, upload_bytes, total_bytes)
            SELECT resolved.bucketAt,
                   'client' scopeType,
                   d.client_id scopeId,
                   SUM(CASE WHEN resolved.direction = 'download' THEN COALESCE(resolved.bytes, 0) ELSE 0 END) downloadBytes,
                   SUM(CASE WHEN resolved.direction = 'upload' THEN COALESCE(resolved.bytes, 0) ELSE 0 END) uploadBytes,
                   SUM(COALESCE(resolved.bytes, 0)) totalBytes
            FROM (
                SELECT DATE_FORMAT(f.received_at, '%Y-%m-%d %H:00:00') bucketAt,
                       COALESCE(f.device_id, d.id) deviceId,
                       f.direction,
                       f.bytes
                FROM network_flow f
                LEFT JOIN device d ON f.device_id IS NULL AND (
                    (f.direction = 'upload' AND d.current_ip = f.src_ip)
                    OR (f.direction = 'download' AND d.current_ip = COALESCE(f.post_nat_dst_ip, f.dst_ip))
                )
                WHERE f.received_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
            ) resolved
            INNER JOIN device d ON d.id = resolved.deviceId
            WHERE d.client_id IS NOT NULL
            GROUP BY resolved.bucketAt, d.client_id
            ON DUPLICATE KEY UPDATE
                download_bytes = VALUES(download_bytes),
                upload_bytes = VALUES(upload_bytes),
                total_bytes = VALUES(total_bytes)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE traffic_hourly_usage');
    }
}
