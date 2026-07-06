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
        return $this->ensureCache(
            'clients',
            fn (): array => $this->refreshClients(),
            fn (array $rows): bool => $rows === [] || (isset($rows[0]['lastSeen']) && isset($rows[0]['deviceCount']))
        );
    }

    public function cachedDeviceDetail(int $deviceId): array
    {
        $details = $this->ensureCache(
            'device_details',
            fn (): array => $this->refreshDevices(),
            fn (array $details): bool => !$this->detailCacheUsesLegacyTimestamps($details)
        );

        return $details[$deviceId] ?? $this->emptyDetailPayload();
    }

    public function cachedClientDetail(int $clientId): array
    {
        $details = $this->ensureCache(
            'client_details',
            fn (): array => $this->refreshClients(),
            fn (array $details): bool => !$this->detailCacheUsesLegacyTimestamps($details)
        );

        return $details[$clientId] ?? $this->emptyDetailPayload();
    }

    public function refresh(): array
    {
        $deviceRows = $this->buildDeviceRows();
        $deviceDetails = $this->buildDeviceDetails($deviceRows);
        $clientRows = $this->buildClientRows($deviceRows);
        $clientDetails = $this->buildClientDetails($clientRows, $deviceRows, $deviceDetails);

        $this->writeCache('devices', $deviceRows);
        $this->writeCache('device_details', $deviceDetails);
        $this->writeCache('clients', $clientRows);
        $this->writeCache('client_details', $clientDetails);

        return [
            'devices' => count($deviceRows),
            'clients' => count($clientRows),
        ];
    }

    public function refreshDevices(): array
    {
        $deviceRows = $this->buildDeviceRows();
        $deviceDetails = $this->buildDeviceDetails($deviceRows);

        $this->writeCache('devices', $deviceRows);
        $this->writeCache('device_details', $deviceDetails);

        return [
            'devices' => count($deviceRows),
        ];
    }

    public function refreshClients(): array
    {
        $deviceRows = $this->buildDeviceRows();
        $deviceDetails = $this->buildDeviceDetails($deviceRows);
        $clientRows = $this->buildClientRows($deviceRows);
        $clientDetails = $this->buildClientDetails($clientRows, $deviceRows, $deviceDetails);

        $this->writeCache('clients', $clientRows);
        $this->writeCache('client_details', $clientDetails);

        return [
            'clients' => count($clientRows),
        ];
    }

    public function devicesCacheUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->cacheUpdatedAt('devices');
    }

    public function clientsCacheUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->cacheUpdatedAt('clients');
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

    private function buildDeviceDetails(array $deviceRows): array
    {
        $deviceIds = array_map(fn (array $row): int => (int) $row['device']['id'], $deviceRows);

        $details = [];
        foreach ($deviceRows as $row) {
            $deviceId = (int) $row['device']['id'];
            $details[$deviceId] = array_merge($this->emptyDetailPayload(), [
                'today' => (int) $row['today'],
                'todayDownload' => (int) $row['todayDownload'],
                'todayUpload' => (int) $row['todayUpload'],
                'month' => (int) $row['month'],
            ]);
        }

        foreach ($this->dailyUsageByDevice($deviceIds) as $deviceId => $daily) {
            $details[$deviceId]['daily'] = $daily;
        }
        foreach ($this->recentDomainsByDevice() as $deviceId => $rows) {
            $details[$deviceId]['recentDomains'] = $rows;
        }
        foreach ($this->topDomainsByDeviceFromFlows(10) as $deviceId => $rows) {
            $details[$deviceId]['topDomainsToday'] = $rows;
        }
        foreach ($this->topAppsByDevice() as $deviceId => $rows) {
            $details[$deviceId]['topAppsToday'] = $rows;
        }
        foreach ($this->recentActivityByDevice() as $deviceId => $rows) {
            $details[$deviceId]['recentActivity'] = $rows;
        }

        return $details;
    }

    private function buildClientDetails(array $clientRows, array $deviceRows, array $deviceDetails): array
    {
        $deviceIdsByClient = [];
        foreach ($deviceRows as $deviceRow) {
            $clientId = $deviceRow['device']['clientId'] ?? null;
            if ($clientId === null) {
                continue;
            }

            $deviceIdsByClient[(int) $clientId][] = (int) $deviceRow['device']['id'];
        }

        $details = [];
        foreach ($clientRows as $clientRow) {
            $clientId = (int) $clientRow['client']['id'];
            $payload = array_merge($this->emptyDetailPayload(), [
                'today' => (int) $clientRow['today'],
                'todayDownload' => (int) $clientRow['todayDownload'],
                'todayUpload' => (int) $clientRow['todayUpload'],
                'month' => (int) $clientRow['month'],
            ]);

            $daily = [];
            $recentDomains = [];
            $topDomains = [];
            $topApps = [];
            $recentActivity = [];

            foreach ($deviceIdsByClient[$clientId] ?? [] as $deviceId) {
                $deviceDetail = $deviceDetails[$deviceId] ?? $this->emptyDetailPayload();
                foreach ($deviceDetail['daily'] ?? [] as $day) {
                    $date = $day['date'];
                    $daily[$date] = ($daily[$date] ?? 0) + (int) $day['bytes'];
                }
                foreach ($deviceDetail['recentDomains'] ?? [] as $domainRow) {
                    $domain = $domainRow['domain'];
                    $recentDomains[$domain] ??= ['domain' => $domain, 'lastSeenAt' => $domainRow['lastSeenAt'], 'bytes' => 0];
                    $recentDomains[$domain]['bytes'] += (int) $domainRow['bytes'];
                    if (($domainRow['lastSeenAt'] ?? '') > ($recentDomains[$domain]['lastSeenAt'] ?? '')) {
                        $recentDomains[$domain]['lastSeenAt'] = $domainRow['lastSeenAt'];
                    }
                }
                foreach ($deviceDetail['topDomainsToday'] ?? [] as $domainRow) {
                    $topDomains[$domainRow['domain']] = ($topDomains[$domainRow['domain']] ?? 0) + (int) $domainRow['bytes'];
                }
                foreach ($deviceDetail['topAppsToday'] ?? [] as $app => $bytes) {
                    $topApps[$app] = ($topApps[$app] ?? 0) + (int) $bytes;
                }
                foreach ($deviceDetail['recentActivity'] ?? [] as $activityRow) {
                    $recentActivity[] = $activityRow;
                }
            }

            ksort($daily);
            arsort($topDomains);
            arsort($topApps);
            usort($recentDomains, fn (array $a, array $b): int => strcmp((string) $b['lastSeenAt'], (string) $a['lastSeenAt']));
            usort($recentActivity, fn (array $a, array $b): int => strcmp((string) $b['receivedAt'], (string) $a['receivedAt']));

            $payload['daily'] = array_map(fn (string $date, int $bytes): array => ['date' => $date, 'bytes' => $bytes], array_keys($daily), array_values($daily));
            $payload['recentDomains'] = array_slice(array_values($recentDomains), 0, 20);
            $payload['topDomainsToday'] = array_map(fn (string $domain, int $bytes): array => ['domain' => $domain, 'bytes' => $bytes], array_keys(array_slice($topDomains, 0, 10, true)), array_values(array_slice($topDomains, 0, 10, true)));
            $payload['topAppsToday'] = array_slice($topApps, 0, 10, true);
            $payload['recentActivity'] = array_slice($recentActivity, 0, 20);

            $details[$clientId] = $payload;
        }

        return $details;
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
                device_id deviceId,
                COALESCE(SUM(bytes), 0) totalBytes,
                COALESCE(SUM(CASE WHEN direction = :download THEN bytes ELSE 0 END), 0) downloadBytes,
                COALESCE(SUM(CASE WHEN direction = :upload THEN bytes ELSE 0 END), 0) uploadBytes
             FROM network_flow
             WHERE device_id IS NOT NULL
               AND received_at BETWEEN :start AND :end
             GROUP BY device_id',
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
            'SELECT device_id deviceId, COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE device_id IS NOT NULL
               AND received_at BETWEEN :start AND :end
             GROUP BY device_id',
            $this->trafficRangeParameters($days)
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
            'SELECT deviceId, domain, COALESCE(SUM(bytes), 0) totalBytes
             FROM (
                 SELECT device_id deviceId, domain, bytes
                 FROM network_flow
                 WHERE device_id IS NOT NULL
                   AND received_at BETWEEN :start AND :end
                   AND domain IS NOT NULL
                   AND TRIM(domain) <> \'\'
                   AND LOWER(TRIM(domain)) <> \'unknown\'
                 ORDER BY received_at DESC
                 LIMIT 50000
             ) recent_flows
             GROUP BY deviceId, domain
             ORDER BY deviceId ASC, totalBytes DESC',
            $this->trafficRangeParameters(1)
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

    private function dailyUsageByDevice(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT device_id deviceId, date usageDate, total_bytes totalBytes
             FROM device_daily_usage
             WHERE device_id IN ('.implode(',', array_map('intval', $deviceIds)).')
               AND date >= :from
             ORDER BY date ASC',
            ['from' => (new \DateTimeImmutable('-29 days'))->format('Y-m-d')]
        );

        $daily = [];
        foreach ($rows as $row) {
            $daily[(int) $row['deviceId']][] = [
                'date' => (string) $row['usageDate'],
                'bytes' => (int) $row['totalBytes'],
            ];
        }

        return $daily;
    }

    private function recentDomainsByDevice(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT deviceId, domain, MAX(receivedAt) lastSeenAt, COALESCE(SUM(bytes), 0) totalBytes
             FROM (
                 SELECT device_id deviceId, domain, received_at receivedAt, bytes
                 FROM network_flow
                 WHERE device_id IS NOT NULL
                   AND received_at >= :from
                   AND domain IS NOT NULL
                   AND TRIM(domain) <> \'\'
                   AND LOWER(TRIM(domain)) <> \'unknown\'
                 ORDER BY received_at DESC
                 LIMIT 20000
             ) recent_flows
             GROUP BY deviceId, domain
             ORDER BY deviceId ASC, lastSeenAt DESC',
            ['from' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]
        );

        $domainsByDevice = [];
        foreach ($rows as $row) {
            $deviceId = (int) $row['deviceId'];
            if (count($domainsByDevice[$deviceId] ?? []) >= 20) {
                continue;
            }

            $domainsByDevice[$deviceId][] = [
                'domain' => (string) $row['domain'],
                'lastSeenAt' => $this->formatDateTimeAtom($row['lastSeenAt'] ?? null),
                'bytes' => (int) $row['totalBytes'],
            ];
        }

        return $domainsByDevice;
    }

    private function topAppsByDevice(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT deviceId, appName, COALESCE(SUM(bytes), 0) totalBytes
             FROM (
                 SELECT device_id deviceId, app_name appName, bytes
                 FROM network_flow
                 WHERE device_id IS NOT NULL
                   AND received_at BETWEEN :start AND :end
                   AND app_name IS NOT NULL
                   AND LOWER(app_name) <> \'unknown\'
                 ORDER BY received_at DESC
                 LIMIT 50000
             ) recent_flows
             GROUP BY deviceId, appName
             ORDER BY deviceId ASC, totalBytes DESC',
            $this->trafficRangeParameters(1)
        );

        $appsByDevice = [];
        foreach ($rows as $row) {
            $deviceId = (int) $row['deviceId'];
            if (count($appsByDevice[$deviceId] ?? []) >= 10) {
                continue;
            }

            $label = $this->normalizeAppName(isset($row['appName']) ? (string) $row['appName'] : null);
            $appsByDevice[$deviceId][$label] = ($appsByDevice[$deviceId][$label] ?? 0) + (int) $row['totalBytes'];
        }

        return $appsByDevice;
    }

    private function recentActivityByDevice(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT device_id deviceId, received_at receivedAt, direction, domain, app_name appName, bytes, src_ip srcIp, dst_ip dstIp, src_port srcPort, dst_port dstPort
             FROM network_flow
             WHERE device_id IS NOT NULL
             ORDER BY received_at DESC
             LIMIT 5000'
        );

        $activityByDevice = [];
        foreach ($rows as $row) {
            $deviceId = (int) $row['deviceId'];
            if (count($activityByDevice[$deviceId] ?? []) >= 20) {
                continue;
            }

            $activityByDevice[$deviceId][] = [
                'receivedAt' => $this->formatDateTimeAtom($row['receivedAt'] ?? null),
                'direction' => $this->directionLabel((string) ($row['direction'] ?? '')),
                'label' => $this->activityLabel($row),
                'bytes' => (int) ($row['bytes'] ?? 0),
            ];
        }

        return $activityByDevice;
    }

    private function emptyDetailPayload(): array
    {
        return [
            'today' => 0,
            'todayDownload' => 0,
            'todayUpload' => 0,
            'month' => 0,
            'daily' => [],
            'recentDomains' => [],
            'topDomainsToday' => [],
            'topAppsToday' => [],
            'recentActivity' => [],
        ];
    }

    private function normalizeAppName(?string $label): string
    {
        $label = $label !== null ? trim($label) : '';

        return $label === '' || is_numeric($label) ? 'Unknown' : $label;
    }

    private function directionLabel(string $direction): string
    {
        return match ($direction) {
            'upload' => 'Вихідний',
            'download' => 'Вхідний',
            'local' => 'Локальний',
            default => 'Зовнішній',
        };
    }

    private function activityLabel(array $row): string
    {
        $domain = isset($row['domain']) ? trim((string) $row['domain']) : '';
        if ($domain !== '' && strcasecmp($domain, 'unknown') !== 0) {
            return $domain;
        }

        $appName = $this->normalizeAppName(isset($row['appName']) ? (string) $row['appName'] : null);
        if ($appName !== 'Unknown') {
            return $appName;
        }

        $direction = (string) ($row['direction'] ?? '');
        $src = $this->formatEndpoint($row['srcIp'] ?? null, $row['srcPort'] ?? null);
        $dst = $this->formatEndpoint($row['dstIp'] ?? null, $row['dstPort'] ?? null);

        return match ($direction) {
            'upload' => $dst,
            'download' => $src,
            default => $dst !== '-' ? $dst : $src,
        };
    }

    private function formatEndpoint(mixed $ip, mixed $port): string
    {
        $ip = is_string($ip) ? $ip : '';
        if ($ip === '') {
            return '-';
        }

        return is_numeric($port) ? sprintf('%s:%d', $ip, (int) $port) : $ip;
    }

    private function formatDateTimeAtom(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Exception) {
            return $value;
        }
    }

    private function detailCacheUsesLegacyTimestamps(array $details): bool
    {
        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            foreach (['recentDomains', 'recentActivity'] as $section) {
                foreach ($detail[$section] ?? [] as $row) {
                    foreach (['receivedAt', 'lastSeenAt'] as $field) {
                        $value = $row[$field] ?? null;
                        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
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

    /**
     * @param callable():mixed $refresh
     *
     * @return array<string, mixed>
     */
    /**
     * @param callable():mixed $refresh
     * @param null|callable(array):bool $isFresh
     *
     * @return array<string, mixed>|list<mixed>
     */
    private function ensureCache(string $name, callable $refresh, ?callable $isFresh = null): array
    {
        $cached = $this->readCache($name);
        if ($cached !== null && ($isFresh === null || $isFresh($cached))) {
            return $cached;
        }

        $refresh();

        return $this->readCache($name) ?? [];
    }

    private function writeCache(string $name, array $payload): void
    {
        $path = $this->cachePath($name);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (!is_dir($directory)) {
            return;
        }

        $temporaryPath = tempnam($directory, basename($path).'.');
        if ($temporaryPath === false) {
            return;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporaryPath, $encoded) === false) {
            @unlink($temporaryPath);

            return;
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
        }
    }

    private function cachePath(string $name): string
    {
        return $this->kernel->getProjectDir().'/var/cache/page_'.$name.'.json';
    }

    private function cacheUpdatedAt(string $name): ?\DateTimeImmutable
    {
        $modifiedAt = @filemtime($this->cachePath($name));
        if ($modifiedAt === false) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($modifiedAt);
    }
}
