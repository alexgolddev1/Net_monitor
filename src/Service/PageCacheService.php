<?php

namespace App\Service;

use App\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class PageCacheService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function cachedDeviceRows(bool $hideLinked = false): array
    {
        $rows = $this->readCache('devices');
        if ($rows === null) {
            $rows = $this->emptyDeviceRows();
        }

        if (!$hideLinked) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => ($row['device']['clientId'] ?? null) === null));
    }

    public function cachedClientRows(): array
    {
        return $this->readCache('clients') ?? $this->emptyClientRows();
    }

    public function refresh(): array
    {
        $deviceRows = $this->buildDeviceRows();
        $clientRows = $this->buildClientRows($deviceRows);

        $this->writeCache('devices', $deviceRows);
        $this->writeCache('clients', $clientRows);

        return [
            'devices' => count($deviceRows),
            'clients' => count($clientRows),
        ];
    }

    private function buildDeviceRows(): array
    {
        $todayStats = $this->usageStatsByDeviceFromFlows(1);
        $monthTotals = $this->usageTotalsByDeviceFromFlows(30);
        $topDomains = $this->topDomainsByDeviceFromFlows(3);

        $devices = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                d.id,
                d.mac,
                d.current_ip currentIp,
                d.hostname,
                d.vendor,
                d.vlan,
                d.comment,
                d.last_seen_at lastSeenAt,
                c.id clientId,
                c.full_name clientFullName
             FROM device d
             LEFT JOIN client c ON c.id = d.client_id
             ORDER BY d.last_seen_at DESC'
        );

        $rows = [];
        foreach ($devices as $device) {
            $deviceId = (int) $device['id'];
            $stats = $todayStats[$deviceId] ?? ['total' => 0, 'download' => 0, 'upload' => 0];
            $clientId = $device['clientId'] !== null ? (int) $device['clientId'] : null;

            $rows[] = [
                'device' => [
                    'id' => $deviceId,
                    'mac' => (string) $device['mac'],
                    'currentIp' => $device['currentIp'],
                    'hostname' => $device['hostname'],
                    'vendor' => $device['vendor'],
                    'vlan' => $device['vlan'],
                    'comment' => $device['comment'],
                    'lastSeenAt' => $device['lastSeenAt'],
                    'clientId' => $clientId,
                    'clientDisplayName' => $clientId !== null ? ($device['clientFullName'] ?: 'Client #'.$clientId) : null,
                ],
                'today' => $stats['total'],
                'todayDownload' => $stats['download'],
                'todayUpload' => $stats['upload'],
                'month' => $monthTotals[$deviceId] ?? 0,
                'topDomains' => $topDomains[$deviceId] ?? [],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['today'] <=> $a['today']);

        return $rows;
    }

    private function buildClientRows(array $deviceRows): array
    {
        $clients = $this->em->getRepository(Client::class)->findBy(['status' => 'active'], ['fullName' => 'ASC']);
        $rows = [];

        foreach ($clients as $client) {
            $clientId = $client->getId();
            $today = 0;
            $todayDownload = 0;
            $todayUpload = 0;
            $month = 0;
            $deviceCount = 0;
            $lastSeen = null;

            foreach ($deviceRows as $deviceRow) {
                if (($deviceRow['device']['clientId'] ?? null) !== $clientId) {
                    continue;
                }

                ++$deviceCount;
                $today += (int) $deviceRow['today'];
                $todayDownload += (int) $deviceRow['todayDownload'];
                $todayUpload += (int) $deviceRow['todayUpload'];
                $month += (int) $deviceRow['month'];
                $deviceLastSeen = $deviceRow['device']['lastSeenAt'] ?? null;
                if ($deviceLastSeen !== null && ($lastSeen === null || $deviceLastSeen > $lastSeen)) {
                    $lastSeen = $deviceLastSeen;
                }
            }

            $rows[] = [
                'client' => [
                    'id' => $clientId,
                    'fullName' => $client->getFullName(),
                    'displayName' => $client->getDisplayName(),
                    'roomNumber' => $client->getRoomNumber(),
                    'phone' => $client->getPhone(),
                    'comment' => $client->getComment(),
                    'status' => $client->getStatus(),
                ],
                'today' => $today,
                'todayDownload' => $todayDownload,
                'todayUpload' => $todayUpload,
                'month' => $month,
                'lastSeen' => $lastSeen,
                'deviceCount' => $deviceCount,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['today'] <=> $a['today']);

        return $rows;
    }

    private function emptyDeviceRows(): array
    {
        $devices = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                d.id,
                d.mac,
                d.current_ip currentIp,
                d.hostname,
                d.vendor,
                d.vlan,
                d.comment,
                d.last_seen_at lastSeenAt,
                c.id clientId,
                c.full_name clientFullName
             FROM device d
             LEFT JOIN client c ON c.id = d.client_id
             ORDER BY d.last_seen_at DESC'
        );

        return array_map(function (array $device): array {
            $clientId = $device['clientId'] !== null ? (int) $device['clientId'] : null;

            return [
                'device' => [
                    'id' => (int) $device['id'],
                    'mac' => (string) $device['mac'],
                    'currentIp' => $device['currentIp'],
                    'hostname' => $device['hostname'],
                    'vendor' => $device['vendor'],
                    'vlan' => $device['vlan'],
                    'comment' => $device['comment'],
                    'lastSeenAt' => $device['lastSeenAt'],
                    'clientId' => $clientId,
                    'clientDisplayName' => $clientId !== null ? ($device['clientFullName'] ?: 'Client #'.$clientId) : null,
                ],
                'today' => 0,
                'todayDownload' => 0,
                'todayUpload' => 0,
                'month' => 0,
                'topDomains' => [],
            ];
        }, $devices);
    }

    private function emptyClientRows(): array
    {
        $clients = $this->em->getRepository(Client::class)->findBy(['status' => 'active'], ['fullName' => 'ASC']);

        return array_map(fn (Client $client): array => [
            'client' => [
                'id' => $client->getId(),
                'fullName' => $client->getFullName(),
                'displayName' => $client->getDisplayName(),
                'roomNumber' => $client->getRoomNumber(),
                'phone' => $client->getPhone(),
                'comment' => $client->getComment(),
                'status' => $client->getStatus(),
            ],
            'today' => 0,
            'todayDownload' => 0,
            'todayUpload' => 0,
            'month' => 0,
            'lastSeen' => null,
            'deviceCount' => count($client->getDevices()),
        ], $clients);
    }

    private function usageStatsByDeviceFromFlows(int $days): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                COALESCE(f.device_id, d.id) deviceId,
                COALESCE(SUM(f.bytes), 0) totalBytes,
                COALESCE(SUM(CASE WHEN f.direction = :download THEN f.bytes ELSE 0 END), 0) downloadBytes,
                COALESCE(SUM(CASE WHEN f.direction = :upload THEN f.bytes ELSE 0 END), 0) uploadBytes
             FROM network_flow f
             LEFT JOIN device d ON f.device_id IS NULL
               AND (
                   (f.direction = :upload AND d.current_ip = f.src_ip)
                   OR (f.direction = :download AND d.current_ip = COALESCE(f.post_nat_dst_ip, f.dst_ip))
               )
             WHERE COALESCE(f.device_id, d.id) IS NOT NULL
               AND f.received_at BETWEEN :start AND :end
             GROUP BY COALESCE(f.device_id, d.id)',
            ['download' => 'download', 'upload' => 'upload'] + $this->trafficRangeParameters($days)
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row['deviceId']] = [
                'total' => (int) $row['totalBytes'],
                'download' => (int) $row['downloadBytes'],
                'upload' => (int) $row['uploadBytes'],
            ];
        }

        return $stats;
    }

    private function usageTotalsByDeviceFromFlows(int $days): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT COALESCE(f.device_id, d.id) deviceId, COALESCE(SUM(f.bytes), 0) totalBytes
             FROM network_flow f
             LEFT JOIN device d ON f.device_id IS NULL
               AND (
                   (f.direction = :upload AND d.current_ip = f.src_ip)
                   OR (f.direction = :download AND d.current_ip = COALESCE(f.post_nat_dst_ip, f.dst_ip))
               )
             WHERE COALESCE(f.device_id, d.id) IS NOT NULL
               AND f.received_at BETWEEN :start AND :end
             GROUP BY COALESCE(f.device_id, d.id)',
            ['download' => 'download', 'upload' => 'upload'] + $this->trafficRangeParameters($days)
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['deviceId']] = (int) $row['totalBytes'];
        }

        return $totals;
    }

    private function topDomainsByDeviceFromFlows(int $limitPerDevice): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT COALESCE(f.device_id, d.id) deviceId, f.domain domain, COALESCE(SUM(f.bytes), 0) totalBytes
             FROM network_flow f
             LEFT JOIN device d ON f.device_id IS NULL
               AND (
                   (f.direction = :upload AND d.current_ip = f.src_ip)
                   OR (f.direction = :download AND d.current_ip = COALESCE(f.post_nat_dst_ip, f.dst_ip))
               )
             WHERE COALESCE(f.device_id, d.id) IS NOT NULL
               AND f.received_at BETWEEN :start AND :end
               AND f.domain IS NOT NULL
               AND TRIM(f.domain) <> \'\'
               AND LOWER(TRIM(f.domain)) <> \'unknown\'
             GROUP BY COALESCE(f.device_id, d.id), f.domain
             ORDER BY deviceId ASC, totalBytes DESC',
            ['download' => 'download', 'upload' => 'upload'] + $this->trafficRangeParameters(1)
        );

        $domainsByDevice = [];
        foreach ($rows as $row) {
            $deviceId = (int) $row['deviceId'];
            if (count($domainsByDevice[$deviceId] ?? []) >= $limitPerDevice) {
                continue;
            }

            $domainsByDevice[$deviceId][] = [
                'domain' => (string) $row['domain'],
                'bytes' => (int) $row['totalBytes'],
            ];
        }

        return $domainsByDevice;
    }

    private function trafficRangeParameters(int $days): array
    {
        $today = new \DateTimeImmutable('today');
        $start = $today->modify('-'.max(0, $days - 1).' days')->setTime(0, 0);

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $today->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
        ];
    }

    private function readCache(string $name): ?array
    {
        $path = $this->cachePath($name);
        if (!is_file($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : null;
    }

    private function writeCache(string $name, array $payload): void
    {
        $path = $this->cachePath($name);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $temporaryPath = $path.'.tmp';
        file_put_contents($temporaryPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($temporaryPath, $path);
    }

    private function cachePath(string $name): string
    {
        return $this->kernel->getProjectDir().'/var/cache/page_'.$name.'.json';
    }
}
