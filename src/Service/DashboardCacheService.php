<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class DashboardCacheService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function cachedPayload(): array
    {
        $payload = $this->readCache();

        if ($payload !== null) {
            $payload['cacheStatus'] = 'hit';
            $payload['cacheAgeSeconds'] = $this->cacheAgeSeconds();

            return $payload;
        }

        return $this->emptyPayload('missing');
    }

    public function refreshPayload(): array
    {
        $payload = $this->buildPayload();
        $this->writeCache($payload);

        $payload['cacheStatus'] = 'refreshed';
        $payload['cacheAgeSeconds'] = 0;

        return $payload;
    }

    private function buildPayload(): array
    {
        $todayRange = $this->todayRangeParameters();

        return [
            'clients' => $this->em->getRepository(Client::class)->count(['status' => 'active']),
            'devices' => $this->em->getRepository(Device::class)->count([]),
            'unlinkedDevices' => (int) $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\Device d WHERE d.client IS NULL')->getSingleScalarResult(),
            'todayTraffic' => $this->todayTrafficFromFlows($todayRange),
            'todayTrafficBreakdown' => $this->todayTrafficBreakdownFromFlows($todayRange),
            'topDevices' => $this->topDeviceRowsFromFlows($todayRange),
            'topClients' => $this->topClientRowsFromFlows($todayRange),
            'topApps' => $this->topAppsFromFlows($todayRange),
            'topDomains' => $this->topDestinationsFromFlows($todayRange),
            'latestDevices' => $this->latestDevices(),
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function emptyPayload(string $status): array
    {
        return [
            'clients' => $this->em->getRepository(Client::class)->count(['status' => 'active']),
            'devices' => $this->em->getRepository(Device::class)->count([]),
            'unlinkedDevices' => (int) $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\Device d WHERE d.client IS NULL')->getSingleScalarResult(),
            'todayTraffic' => 0,
            'todayTrafficBreakdown' => ['download' => 0, 'upload' => 0, 'local' => 0, 'external' => 0],
            'topDevices' => [],
            'topClients' => [],
            'topApps' => [],
            'topDomains' => [],
            'latestDevices' => $this->latestDevices(),
            'generatedAt' => null,
            'cacheStatus' => $status,
            'cacheAgeSeconds' => null,
        ];
    }

    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : null;
    }

    private function writeCache(array $payload): void
    {
        $path = $this->cachePath();
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

    private function cachePath(): string
    {
        return $this->kernel->getProjectDir().'/var/cache/dashboard_payload.json';
    }

    private function cacheAgeSeconds(): ?int
    {
        $modifiedAt = @filemtime($this->cachePath());

        return $modifiedAt !== false ? time() - $modifiedAt : null;
    }

    private function todayTrafficFromFlows(array $todayRange): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(bytes), 0) FROM network_flow WHERE received_at >= :start AND received_at < :end',
            $todayRange
        );
    }

    private function todayTrafficBreakdownFromFlows(array $todayRange): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT
                COALESCE(SUM(CASE WHEN direction = :download THEN bytes ELSE 0 END), 0) downloadBytes,
                COALESCE(SUM(CASE WHEN direction = :upload THEN bytes ELSE 0 END), 0) uploadBytes,
                COALESCE(SUM(CASE WHEN direction = :local THEN bytes ELSE 0 END), 0) localBytes,
                COALESCE(SUM(CASE WHEN direction = :external THEN bytes ELSE 0 END), 0) externalBytes
             FROM network_flow
             WHERE received_at >= :start AND received_at < :end',
            [
                'download' => 'download',
                'upload' => 'upload',
                'local' => 'local',
                'external' => 'external',
            ] + $todayRange
        );

        return [
            'download' => (int) ($row['downloadBytes'] ?? 0),
            'upload' => (int) ($row['uploadBytes'] ?? 0),
            'local' => (int) ($row['localBytes'] ?? 0),
            'external' => (int) ($row['externalBytes'] ?? 0),
        ];
    }

    private function topDeviceRowsFromFlows(array $todayRange): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                d.id,
                d.mac,
                d.current_ip currentIp,
                COALESCE(SUM(f.bytes), 0) totalBytes,
                COALESCE(SUM(CASE WHEN f.direction = :download THEN f.bytes ELSE 0 END), 0) downloadBytes,
                COALESCE(SUM(CASE WHEN f.direction = :upload THEN f.bytes ELSE 0 END), 0) uploadBytes
             FROM network_flow f
             INNER JOIN device d ON d.id = f.device_id
             WHERE f.received_at >= :start AND f.received_at < :end
             GROUP BY d.id, d.mac, d.current_ip
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['download' => 'download', 'upload' => 'upload'] + $todayRange
        );

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'mac' => (string) $row['mac'],
            'currentIp' => $row['currentIp'],
            'today' => (int) $row['totalBytes'],
            'todayDownload' => (int) $row['downloadBytes'],
            'todayUpload' => (int) $row['uploadBytes'],
        ], $rows);
    }

    private function topClientRowsFromFlows(array $todayRange): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                c.id,
                c.full_name fullName,
                c.room_number roomNumber,
                COALESCE(SUM(f.bytes), 0) totalBytes,
                COALESCE(SUM(CASE WHEN f.direction = :download THEN f.bytes ELSE 0 END), 0) downloadBytes,
                COALESCE(SUM(CASE WHEN f.direction = :upload THEN f.bytes ELSE 0 END), 0) uploadBytes
             FROM network_flow f
             INNER JOIN client c ON c.id = f.client_id
             WHERE f.received_at >= :start AND f.received_at < :end
             GROUP BY c.id, c.full_name, c.room_number
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['download' => 'download', 'upload' => 'upload'] + $todayRange
        );

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'fullName' => $row['fullName'],
            'displayName' => $row['fullName'] ?: 'Клієнт #'.$row['id'],
            'roomNumber' => $row['roomNumber'],
            'today' => (int) $row['totalBytes'],
            'todayDownload' => (int) $row['downloadBytes'],
            'todayUpload' => (int) $row['uploadBytes'],
        ], $rows);
    }

    private function topAppsFromFlows(array $todayRange): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                appName,
                COALESCE(SUM(bytes), 0) totalBytes
             FROM (
                 SELECT app_name appName, bytes
                 FROM network_flow
                 WHERE received_at >= :start AND received_at < :end
                   AND app_name IS NOT NULL
                   AND app_name <> \'unknown\'
                   AND app_name <> \'Unknown\'
                 ORDER BY received_at DESC
                 LIMIT 50000
             ) recent_flows
             GROUP BY appName
             ORDER BY totalBytes DESC
             LIMIT 10',
            $todayRange
        );

        $apps = [];
        foreach ($rows as $row) {
            $label = $this->normalizeAppName(isset($row['appName']) ? (string) $row['appName'] : null);
            $apps[$label] = ($apps[$label] ?? 0) + (int) $row['totalBytes'];
        }

        return $apps;
    }

    private function topDestinationsFromFlows(array $todayRange): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                domain,
                COALESCE(SUM(bytes), 0) totalBytes
             FROM (
                 SELECT domain, bytes
                 FROM network_flow
                 WHERE received_at >= :start AND received_at < :end
                   AND domain IS NOT NULL
                   AND domain <> \'\'
                   AND domain <> \'unknown\'
                   AND domain <> \'Unknown\'
                 ORDER BY received_at DESC
                 LIMIT 50000
             ) recent_flows
             GROUP BY domain
             ORDER BY totalBytes DESC
             LIMIT 10',
            $todayRange
        );

        $destinations = [];
        foreach ($rows as $row) {
            $domain = isset($row['domain']) ? trim((string) $row['domain']) : '';
            if ($domain === '' || strcasecmp($domain, 'unknown') === 0) {
                continue;
            }
            $destinations[$domain] = ($destinations[$domain] ?? 0) + (int) $row['totalBytes'];
        }

        arsort($destinations);

        return $destinations;
    }

    private function latestDevices(): array
    {
        $devices = $this->em->getRepository(Device::class)->findBy([], ['firstSeenAt' => 'DESC'], 10);
        $payload = [];

        foreach ($devices as $device) {
            $payload[] = [
                'id' => $device->getId(),
                'mac' => $device->getMac(),
                'hostname' => $device->getHostname(),
                'firstSeenAt' => $device->getFirstSeenAt()?->format(DATE_ATOM),
            ];
        }

        return $payload;
    }

    private function normalizeAppName(?string $appName): string
    {
        $appName = $appName !== null ? trim($appName) : '';

        return $appName === '' || is_numeric($appName) ? 'Unknown' : $appName;
    }

    private function todayRangeParameters(): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            'start' => $today->setTime(0, 0)->format('Y-m-d H:i:s'),
            'end' => $today->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'),
        ];
    }
}
