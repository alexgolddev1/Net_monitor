<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\DeviceDailyUsage;
use App\Entity\TrafficSnapshot;
use App\Service\ApplicationLabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class TrafficAggregator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly ApplicationLabelResolver $applicationLabelResolver,
    ) {
    }

    public function aggregateDaily(?\DateTimeImmutable $date = null): int
    {
        $date ??= new \DateTimeImmutable('today');
        $start = $date->setTime(0, 0);
        $end = $date->setTime(23, 59, 59);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT device_id deviceId,
                    SUM(CASE WHEN direction = :download THEN COALESCE(bytes, 0) ELSE 0 END) bytesIn,
                    SUM(CASE WHEN direction = :upload THEN COALESCE(bytes, 0) ELSE 0 END) bytesOut,
                    SUM(COALESCE(bytes, 0)) totalBytes
             FROM network_flow
             WHERE device_id IS NOT NULL AND received_at BETWEEN :start AND :end
             GROUP BY device_id',
            [
                'download' => 'download',
                'upload' => 'upload',
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        );

        foreach ($rows as $row) {
            $device = $this->em->getRepository(Device::class)->find((int) $row['deviceId']);
            if (!$device) {
                continue;
            }
            $usage = $this->em->getRepository(DeviceDailyUsage::class)->findOneBy(['device' => $device, 'date' => $date]);
            if (!$usage) {
                $usage = (new DeviceDailyUsage())->setDevice($device)->setDate($date);
                $this->em->persist($usage);
            }
            $usage
                ->setBytesIn((int) $row['bytesIn'])
                ->setBytesOut((int) $row['bytesOut'])
                ->setTotalBytes((int) $row['totalBytes'])
                ->setTopAppsJson($this->topAppsFromFlows($device, $start, $end))
                ->setTopDestinationsJson($this->topDestinationsFromFlows($device, $start, $end));
        }

        $this->connection->executeStatement(
            'INSERT INTO traffic_daily_direction_usage (date, direction, bytes)
             SELECT DATE(received_at) usageDate,
                    direction,
                    SUM(COALESCE(bytes, 0)) bytes
             FROM network_flow
             WHERE device_id IS NOT NULL AND received_at BETWEEN :start AND :end
             GROUP BY DATE(received_at), direction
             ON DUPLICATE KEY UPDATE bytes = VALUES(bytes)',
            [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        );

        $this->upsertHourlyGraphUsageByDateRange($start, $end);

        $this->em->flush();

        return count($rows);
    }

    public function aggregateIncremental(int $batchSize = 50000): int
    {
        $batchSize = max(1, $batchSize);
        $processed = 0;
        $lastFlowId = $this->aggregationStateLastFlowId();
        $maxFlowId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM network_flow');

        while ($lastFlowId < $maxFlowId) {
            $toFlowId = min($lastFlowId + $batchSize, $maxFlowId);
            $processed += $this->aggregateFlowRange($lastFlowId, $toFlowId);
            $lastFlowId = $toFlowId;
            $this->setAggregationStateLastFlowId($lastFlowId);
        }

        return $processed;
    }

    private function aggregateFlowRange(int $fromFlowId, int $toFlowId): int
    {
        if ($toFlowId <= $fromFlowId) {
            return 0;
        }

        $params = [
            'fromId' => $fromFlowId,
            'toId' => $toFlowId,
            'download' => 'download',
            'upload' => 'upload',
        ];

        $processed = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM network_flow WHERE id > :fromId AND id <= :toId',
            ['fromId' => $fromFlowId, 'toId' => $toFlowId]
        );

        $this->connection->executeStatement(
            'INSERT INTO device_daily_usage (device_id, date, bytes_in, bytes_out, total_bytes)
             SELECT resolved.deviceId,
                    resolved.usageDate,
                    SUM(CASE WHEN resolved.direction = :download THEN COALESCE(resolved.bytes, 0) ELSE 0 END) bytesIn,
                    SUM(CASE WHEN resolved.direction = :upload THEN COALESCE(resolved.bytes, 0) ELSE 0 END) bytesOut,
                    SUM(COALESCE(resolved.bytes, 0)) totalBytes
             FROM (
                 SELECT COALESCE(f.device_id, d.id) deviceId,
                        DATE(f.received_at) usageDate,
                        f.direction,
                        f.bytes
                 FROM network_flow f
                 LEFT JOIN device d ON f.device_id IS NULL AND (
                     (f.direction = :upload AND d.current_ip = f.src_ip)
                     OR (f.direction = :download AND d.current_ip = COALESCE(f.post_nat_dst_ip, f.dst_ip))
                 )
                 WHERE f.id > :fromId
                   AND f.id <= :toId
             ) resolved
             WHERE resolved.deviceId IS NOT NULL
             GROUP BY resolved.deviceId, resolved.usageDate
             ON DUPLICATE KEY UPDATE
                bytes_in = bytes_in + VALUES(bytes_in),
                bytes_out = bytes_out + VALUES(bytes_out),
                total_bytes = total_bytes + VALUES(total_bytes)',
            $params
        );

        $this->connection->executeStatement(
            'INSERT INTO device_daily_app_usage (device_id, date, app_name, bytes)
             SELECT resolved.deviceId,
                    resolved.usageDate,
                    resolved.appName,
                    SUM(COALESCE(resolved.bytes, 0)) bytes
             FROM (
                 SELECT COALESCE(f.device_id, d.id) deviceId,
                        DATE(f.received_at) usageDate,
                        CASE
                            WHEN f.app_name IS NULL THEN NULL
                            WHEN LOWER(TRIM(f.app_name)) = \'unknown\' THEN NULL
                            WHEN TRIM(f.app_name) = \'\' THEN NULL
                            ELSE TRIM(f.app_name)
                        END appName,
                        f.bytes
                 FROM network_flow f
                 LEFT JOIN device d ON f.device_id IS NULL AND (
                     (f.direction = :upload AND d.current_ip = f.src_ip)
                     OR (f.direction = :download AND d.current_ip = COALESCE(f.post_nat_dst_ip, f.dst_ip))
                 )
                 WHERE f.id > :fromId
                   AND f.id <= :toId
             ) resolved
             WHERE resolved.deviceId IS NOT NULL
               AND resolved.appName IS NOT NULL
             GROUP BY resolved.deviceId, resolved.usageDate, resolved.appName
             ON DUPLICATE KEY UPDATE
                bytes = bytes + VALUES(bytes)',
            $params
        );

        $this->connection->executeStatement(
            'INSERT INTO device_daily_domain_usage (device_id, date, domain, last_seen_at, bytes)
             SELECT resolved.deviceId,
                    resolved.usageDate,
                    resolved.domain,
                    MAX(resolved.receivedAt) lastSeenAt,
                    SUM(COALESCE(resolved.bytes, 0)) bytes
             FROM (
                 SELECT COALESCE(f.device_id, d.id) deviceId,
                        DATE(f.received_at) usageDate,
                        CASE
                            WHEN f.domain IS NULL THEN NULL
                            WHEN TRIM(f.domain) = \'\' THEN NULL
                            WHEN LOWER(TRIM(f.domain)) = \'unknown\' THEN NULL
                            ELSE LOWER(TRIM(f.domain))
                        END domain,
                        f.received_at receivedAt,
                        f.bytes
                 FROM network_flow f
                 LEFT JOIN device d ON f.device_id IS NULL AND (
                     (f.direction = :upload AND d.current_ip = f.src_ip)
                     OR (f.direction = :download AND d.current_ip = COALESCE(f.post_nat_dst_ip, f.dst_ip))
                 )
                 WHERE f.id > :fromId
                   AND f.id <= :toId
             ) resolved
             WHERE resolved.deviceId IS NOT NULL
               AND resolved.domain IS NOT NULL
             GROUP BY resolved.deviceId, resolved.usageDate, resolved.domain
             ON DUPLICATE KEY UPDATE
                bytes = bytes + VALUES(bytes),
                last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at))',
            $params
        );

        $this->connection->executeStatement(
            'INSERT INTO traffic_daily_direction_usage (date, direction, bytes)
             SELECT resolved.usageDate,
                    resolved.direction,
                    SUM(COALESCE(resolved.bytes, 0)) bytes
             FROM (
                 SELECT DATE(f.received_at) usageDate,
                        f.direction,
                        f.bytes
                 FROM network_flow f
                 WHERE f.id > :fromId
                   AND f.id <= :toId
             ) resolved
             GROUP BY resolved.usageDate, resolved.direction
             ON DUPLICATE KEY UPDATE
                bytes = bytes + VALUES(bytes)',
            $params
        );

        $this->upsertHourlyGraphUsageByFlowRange($fromFlowId, $toFlowId);

        return $processed;
    }

    private function upsertHourlyGraphUsageByDateRange(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $params = [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];

        $this->connection->executeStatement(
            "INSERT INTO traffic_hourly_usage (bucket_at, scope_type, scope_id, download_bytes, upload_bytes, total_bytes)
             SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H:00:00') bucketAt,
                    'all' scopeType,
                    0 scopeId,
                    SUM(CASE WHEN direction = 'download' THEN COALESCE(bytes, 0) ELSE 0 END) downloadBytes,
                    SUM(CASE WHEN direction = 'upload' THEN COALESCE(bytes, 0) ELSE 0 END) uploadBytes,
                    SUM(COALESCE(bytes, 0)) totalBytes
             FROM network_flow
             WHERE received_at BETWEEN :start AND :end
             GROUP BY bucketAt
             ON DUPLICATE KEY UPDATE
                download_bytes = VALUES(download_bytes),
                upload_bytes = VALUES(upload_bytes),
                total_bytes = VALUES(total_bytes)",
            $params
        );

        $this->connection->executeStatement(
            "INSERT INTO traffic_hourly_usage (bucket_at, scope_type, scope_id, download_bytes, upload_bytes, total_bytes)
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
                 WHERE f.received_at BETWEEN :start AND :end
             ) resolved
             WHERE resolved.deviceId IS NOT NULL
             GROUP BY resolved.bucketAt, resolved.deviceId
             ON DUPLICATE KEY UPDATE
                download_bytes = VALUES(download_bytes),
                upload_bytes = VALUES(upload_bytes),
                total_bytes = VALUES(total_bytes)",
            $params
        );

        $this->connection->executeStatement(
            "INSERT INTO traffic_hourly_usage (bucket_at, scope_type, scope_id, download_bytes, upload_bytes, total_bytes)
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
                     OR (f.direction = 'download' AND d.current_ip = COALESCE(f.post_nat_src_ip, f.post_nat_dst_ip, f.dst_ip))
                 )
                 WHERE f.received_at BETWEEN :start AND :end
             ) resolved
             INNER JOIN device d ON d.id = resolved.deviceId
             WHERE d.client_id IS NOT NULL
             GROUP BY resolved.bucketAt, d.client_id
             ON DUPLICATE KEY UPDATE
                download_bytes = VALUES(download_bytes),
                upload_bytes = VALUES(upload_bytes),
                total_bytes = VALUES(total_bytes)",
            $params
        );
    }

    private function upsertHourlyGraphUsageByFlowRange(int $fromFlowId, int $toFlowId): void
    {
        if ($toFlowId <= $fromFlowId) {
            return;
        }

        $params = [
            'fromId' => $fromFlowId,
            'toId' => $toFlowId,
        ];

        $this->connection->executeStatement(
            "INSERT INTO traffic_hourly_usage (bucket_at, scope_type, scope_id, download_bytes, upload_bytes, total_bytes)
             SELECT DATE_FORMAT(received_at, '%Y-%m-%d %H:00:00') bucketAt,
                    'all' scopeType,
                    0 scopeId,
                    SUM(CASE WHEN direction = 'download' THEN COALESCE(bytes, 0) ELSE 0 END) downloadBytes,
                    SUM(CASE WHEN direction = 'upload' THEN COALESCE(bytes, 0) ELSE 0 END) uploadBytes,
                    SUM(COALESCE(bytes, 0)) totalBytes
             FROM network_flow
             WHERE id > :fromId AND id <= :toId
             GROUP BY bucketAt
             ON DUPLICATE KEY UPDATE
                download_bytes = VALUES(download_bytes),
                upload_bytes = VALUES(upload_bytes),
                total_bytes = VALUES(total_bytes)",
            $params
        );

        $this->connection->executeStatement(
            "INSERT INTO traffic_hourly_usage (bucket_at, scope_type, scope_id, download_bytes, upload_bytes, total_bytes)
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
                 WHERE f.id > :fromId AND f.id <= :toId
             ) resolved
             WHERE resolved.deviceId IS NOT NULL
             GROUP BY resolved.bucketAt, resolved.deviceId
             ON DUPLICATE KEY UPDATE
                download_bytes = VALUES(download_bytes),
                upload_bytes = VALUES(upload_bytes),
                total_bytes = VALUES(total_bytes)",
            $params
        );

        $this->connection->executeStatement(
            "INSERT INTO traffic_hourly_usage (bucket_at, scope_type, scope_id, download_bytes, upload_bytes, total_bytes)
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
                     OR (f.direction = 'download' AND d.current_ip = COALESCE(f.post_nat_src_ip, f.post_nat_dst_ip, f.dst_ip))
                 )
                 WHERE f.id > :fromId AND f.id <= :toId
             ) resolved
             INNER JOIN device d ON d.id = resolved.deviceId
             WHERE d.client_id IS NOT NULL
             GROUP BY resolved.bucketAt, d.client_id
             ON DUPLICATE KEY UPDATE
                download_bytes = VALUES(download_bytes),
                upload_bytes = VALUES(upload_bytes),
                total_bytes = VALUES(total_bytes)",
            $params
        );
    }

    private function aggregationStateLastFlowId(): int
    {
        $lastFlowId = $this->connection->fetchOne(
            'SELECT last_flow_id FROM traffic_aggregation_state WHERE id = 1'
        );

        if ($lastFlowId === false || $lastFlowId === null) {
            $this->connection->executeStatement(
                'INSERT INTO traffic_aggregation_state (id, last_flow_id, updated_at) VALUES (1, 0, NOW())
                 ON DUPLICATE KEY UPDATE last_flow_id = last_flow_id, updated_at = updated_at'
            );

            return 0;
        }

        return (int) $lastFlowId;
    }

    private function setAggregationStateLastFlowId(int $lastFlowId): void
    {
        $this->connection->executeStatement(
            'UPDATE traffic_aggregation_state SET last_flow_id = :lastFlowId, updated_at = NOW() WHERE id = 1',
            ['lastFlowId' => $lastFlowId]
        );
    }

    public function totalsForDevice(Device $device, int $days): int
    {
        $from = new \DateTimeImmutable(sprintf('-%d days', $days - 1));
        return (int) $this->em->createQuery(
            'SELECT COALESCE(SUM(u.totalBytes), 0) FROM App\Entity\DeviceDailyUsage u WHERE u.device = :device AND u.date >= :from'
        )->setParameter('device', $device)->setParameter('from', $from)->getSingleScalarResult();
    }

    public function trafficTodayFromFlows(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(bytes), 0) FROM network_flow WHERE received_at >= :start AND received_at <= :end',
            $this->todayParameters()
        );
    }

    public function topDevicesFromFlows(int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT d.id, d.mac, d.current_ip currentIp, d.hostname, COALESCE(SUM(f.bytes), 0) totalBytes
             FROM network_flow f
             INNER JOIN device d ON d.id = f.device_id
             WHERE f.received_at >= :start AND f.received_at <= :end
             GROUP BY d.id, d.mac, d.current_ip, d.hostname
             ORDER BY totalBytes DESC
             LIMIT '.$limit,
            $this->todayParameters()
        );
    }

    public function topClientsFromFlows(int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT c.id, c.full_name fullName, c.room_number roomNumber, COALESCE(SUM(f.bytes), 0) totalBytes
             FROM network_flow f
             INNER JOIN client c ON c.id = f.client_id
             WHERE f.received_at >= :start AND f.received_at <= :end
             GROUP BY c.id, c.full_name, c.room_number
             ORDER BY totalBytes DESC
             LIMIT '.$limit,
            $this->todayParameters()
        );
    }

    public function usageByHourFromFlows(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT HOUR(received_at) hour, COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE received_at >= :start AND received_at <= :end
             GROUP BY HOUR(received_at)
             ORDER BY hour ASC',
            $this->todayParameters()
        );
    }

    public function cleanupSnapshots(int $days = 35): int
    {
        return $this->em->createQuery('DELETE FROM App\Entity\TrafficSnapshot s WHERE s.snapshotAt < :before')
            ->setParameter('before', new \DateTimeImmutable('-'.$days.' days'))
            ->execute();
    }

    public function topFromSnapshots(Device $device, string $field, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $snapshots = $this->em->getRepository(TrafficSnapshot::class)->createQueryBuilder('s')
            ->andWhere('s.device = :device')
            ->andWhere('s.snapshotAt BETWEEN :start AND :end')
            ->setParameter('device', $device)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $totals = [];
        foreach ($snapshots as $snapshot) {
            $items = $field === 'appsJson' ? $snapshot->getAppsJson() : $snapshot->getDestinationsJson();
            foreach ($items ?? [] as $item) {
                $key = $field === 'appsJson'
                    ? $this->applicationLabelResolver->labelForItem($item)
                    : ($item['domain'] ?? $item['name'] ?? null);
                if (!$key) {
                    continue;
                }
                $totals[$key] = ($totals[$key] ?? 0) + (int) ($item['bytes'] ?? 0);
            }
        }
        arsort($totals);

        return array_map(fn ($key, $bytes) => ['name' => $key, 'bytes' => $bytes], array_keys(array_slice($totals, 0, 10, true)), array_values(array_slice($totals, 0, 10, true)));
    }

    public function topAppsFromFlows(Device $device, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                app_name appName,
                COALESCE(SUM(bytes), 0) bytes
             FROM network_flow
             WHERE device_id = :deviceId
               AND received_at BETWEEN :start AND :end
               AND app_name IS NOT NULL
               AND LOWER(app_name) <> \'unknown\'
             GROUP BY appName
             ORDER BY bytes DESC
             LIMIT 10',
            [
                'deviceId' => $device->getId(),
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        );

        $totals = [];
        foreach ($rows as $row) {
            $label = $this->normalizeAppLabel(isset($row['appName']) ? (string) $row['appName'] : null);
            $totals[$label] = ($totals[$label] ?? 0) + (int) $row['bytes'];
        }

        if ($totals === []) {
            $unknown = (int) $this->connection->fetchOne(
                'SELECT COALESCE(SUM(bytes), 0) FROM network_flow WHERE device_id = :deviceId AND received_at BETWEEN :start AND :end AND (app_name IS NULL OR LOWER(app_name) = \'unknown\')',
                [
                    'deviceId' => $device->getId(),
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $end->format('Y-m-d H:i:s'),
                ]
            );
            if ($unknown > 0) {
                $totals['Unknown'] = $unknown;
            }
        }

        return array_map(
            fn (string $name, int $bytes): array => ['name' => $name, 'bytes' => $bytes],
            array_keys(array_slice($totals, 0, 10, true)),
            array_values(array_slice($totals, 0, 10, true))
        );
    }

    public function topDestinationsFromFlows(Device $device, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT domain, COALESCE(SUM(bytes), 0) bytes
             FROM network_flow
             WHERE device_id = :deviceId
               AND received_at BETWEEN :start AND :end
               AND domain IS NOT NULL
               AND TRIM(domain) <> \'\'
               AND LOWER(TRIM(domain)) <> \'unknown\'
             GROUP BY domain
             ORDER BY bytes DESC
             LIMIT 10',
            [
                'deviceId' => $device->getId(),
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        );

        return array_values(array_filter(array_map(function (array $row): ?array {
            $domain = isset($row['domain']) ? trim((string) $row['domain']) : '';
            if ($domain === '' || strcasecmp($domain, 'unknown') === 0) {
                return null;
            }

            return [
                'name' => $domain,
                'domain' => $domain,
                'bytes' => (int) $row['bytes'],
            ];
        }, $rows)));
    }

    private function normalizeAppLabel(?string $label): string
    {
        $label = $label !== null ? trim($label) : '';

        return $label === '' || is_numeric($label) ? 'Unknown' : $label;
    }

    private function todayParameters(): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            'start' => $today->setTime(0, 0)->format('Y-m-d H:i:s'),
            'end' => $today->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
        ];
    }
}
