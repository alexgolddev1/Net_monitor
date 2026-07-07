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
            $payload = $this->normalizePayload($payload);
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
        $today = $this->todayDateParameter();

        return [
            'clients' => $this->em->getRepository(Client::class)->count(['status' => 'active']),
            'devices' => $this->em->getRepository(Device::class)->count([]),
            'unlinkedDevices' => (int) $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\Device d WHERE d.client IS NULL')->getSingleScalarResult(),
            'todayTraffic' => $this->todayTrafficFromRollups($today),
            'todayTrafficBreakdown' => $this->todayTrafficBreakdownFromRollups($today),
            'topDevices' => $this->normalizeTopDeviceRows($this->topDeviceRowsFromRollups($today)),
            'topClients' => $this->topClientRowsFromRollups($today),
            'topApps' => $this->topAppsFromRollups($today),
            'topDomains' => $this->topDestinationsFromRollups($today),
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

    private function todayTrafficFromRollups(string $today): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(total_bytes), 0) FROM device_daily_usage WHERE date = :today',
            ['today' => $today]
        );
    }

    private function todayTrafficBreakdownFromRollups(string $today): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT
                COALESCE(SUM(CASE WHEN direction = :download THEN bytes ELSE 0 END), 0) downloadBytes,
                COALESCE(SUM(CASE WHEN direction = :upload THEN bytes ELSE 0 END), 0) uploadBytes,
                COALESCE(SUM(CASE WHEN direction = :local THEN bytes ELSE 0 END), 0) localBytes,
                COALESCE(SUM(CASE WHEN direction = :external THEN bytes ELSE 0 END), 0) externalBytes
             FROM traffic_daily_direction_usage
             WHERE date = :today',
            [
                'today' => $today,
                'download' => 'download',
                'upload' => 'upload',
                'local' => 'local',
                'external' => 'external',
            ]
        );

        return [
            'download' => (int) ($row['downloadBytes'] ?? 0),
            'upload' => (int) ($row['uploadBytes'] ?? 0),
            'local' => (int) ($row['localBytes'] ?? 0),
            'external' => (int) ($row['externalBytes'] ?? 0),
        ];
    }

    private function topDeviceRowsFromRollups(string $today): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                d.id,
                d.mac,
                d.current_ip currentIp,
                d.comment comment,
                COALESCE(SUM(u.total_bytes), 0) totalBytes,
                COALESCE(SUM(u.bytes_in), 0) downloadBytes,
                COALESCE(SUM(u.bytes_out), 0) uploadBytes
             FROM device_daily_usage u
             INNER JOIN device d ON d.id = u.device_id
             WHERE u.date = :today
             GROUP BY d.id, d.mac, d.current_ip, d.comment
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['today' => $today]
        );

        return array_values(array_filter(array_map(function (array $row): ?array {
            $device = $this->em->getRepository(Device::class)->find((int) $row['id']);
            if (!$device) {
                return null;
            }

            $client = $device->getClient();

            return [
                'id' => $device->getId(),
                'mac' => $device->getMac(),
                'currentIp' => $device->getCurrentIp(),
                'clientId' => $client?->getId(),
                'clientDisplayName' => $client?->getDisplayName(),
                'comment' => $device->getComment(),
                'today' => (int) $row['totalBytes'],
                'todayDownload' => (int) $row['downloadBytes'],
                'todayUpload' => (int) $row['uploadBytes'],
            ];
        }, $rows)));
    }

    private function normalizePayload(array $payload): array
    {
        if (isset($payload['topDevices']) && is_array($payload['topDevices'])) {
            $payload['topDevices'] = $this->normalizeTopDeviceRows($payload['topDevices']);
        }

        return $payload;
    }

    private function normalizeTopDeviceRows(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            if (
                array_key_exists('clientId', $row)
                && array_key_exists('clientDisplayName', $row)
                && array_key_exists('comment', $row)
            ) {
                return $row;
            }

            $deviceId = (int) ($row['id'] ?? 0);
            $device = $deviceId > 0 ? $this->em->getRepository(Device::class)->find($deviceId) : null;
            if (!$device) {
                return $row + [
                    'clientId' => null,
                    'clientDisplayName' => null,
                    'comment' => null,
                ];
            }

            $client = $device->getClient();

            return $row + [
                'id' => $device->getId(),
                'mac' => $device->getMac(),
                'currentIp' => $device->getCurrentIp(),
                'clientId' => $client?->getId(),
                'clientDisplayName' => $client?->getDisplayName(),
                'comment' => $device->getComment(),
            ];
        }, $rows));
    }

    private function topClientRowsFromRollups(string $today): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                c.id,
                c.full_name fullName,
                c.room_number roomNumber,
                COALESCE(SUM(u.total_bytes), 0) totalBytes,
                COALESCE(SUM(u.bytes_in), 0) downloadBytes,
                COALESCE(SUM(u.bytes_out), 0) uploadBytes
             FROM device_daily_usage u
             INNER JOIN device d ON d.id = u.device_id
             INNER JOIN client c ON c.id = d.client_id
             WHERE u.date = :today
             GROUP BY c.id, c.full_name, c.room_number
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['today' => $today]
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

    private function topAppsFromRollups(string $today): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                app_name appName,
                COALESCE(SUM(bytes), 0) totalBytes
             FROM device_daily_app_usage
             WHERE date = :today
             GROUP BY appName
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['today' => $today]
        );

        $apps = [];
        foreach ($rows as $row) {
            $label = $this->normalizeAppName(isset($row['appName']) ? (string) $row['appName'] : null);
            $apps[$label] = ($apps[$label] ?? 0) + (int) $row['totalBytes'];
        }

        return $apps;
    }

    private function topDestinationsFromRollups(string $today): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                domain,
                COALESCE(SUM(bytes), 0) totalBytes
             FROM device_daily_domain_usage
             WHERE date = :today
             GROUP BY domain
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['today' => $today]
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

    private function todayDateParameter(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
