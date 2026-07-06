<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Device;
use App\Service\DashboardCacheService;
use App\Service\PageCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DashboardCacheService $dashboardCache,
        private readonly PageCacheService $pageCache,
    )
    {
    }

    #[Route('/dashboard', methods: ['GET'], name: 'api_dashboard')]
    public function dashboard(): JsonResponse
    {
        return $this->json($this->dashboardCache->cachedPayload());
    }

    #[Route('/clients', methods: ['GET'])]
    public function clients(): JsonResponse
    {
        return $this->json(array_map(fn (Client $client) => $this->clientPayload($client), $this->em->getRepository(Client::class)->findAll()));
    }

    #[Route('/clients/{id}', methods: ['GET'])]
    public function client(Client $client): JsonResponse
    {
        return $this->json($this->clientPayload($client));
    }

    #[Route('/clients/{id}/traffic', methods: ['GET'], name: 'api_client_traffic')]
    public function clientTraffic(Client $client): JsonResponse
    {
        return $this->json($this->pageCache->cachedClientDetail((int) $client->getId()));
    }

    #[Route('/devices', methods: ['GET'])]
    public function devices(): JsonResponse
    {
        return $this->json(array_map(fn (Device $device) => $this->devicePayload($device), $this->em->getRepository(Device::class)->findAll()));
    }

    #[Route('/devices/{id}', methods: ['GET'])]
    public function device(Device $device): JsonResponse
    {
        return $this->json($this->devicePayload($device));
    }

    #[Route('/devices/{id}/traffic', methods: ['GET'], name: 'api_device_traffic')]
    public function deviceTraffic(Device $device): JsonResponse
    {
        return $this->json($this->pageCache->cachedDeviceDetail((int) $device->getId()));
    }

    #[Route('/devices/{id}/link-client', methods: ['POST'])]
    public function linkClient(Device $device, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: $request->request->all();
        $client = isset($data['clientId']) ? $this->em->getRepository(Client::class)->find((int) $data['clientId']) : null;
        if (!$client) {
            $client = (new Client())
                ->setFullName($data['fullName'] ?? null)
                ->setRoomNumber($data['roomNumber'] ?? null)
                ->setPhone($data['phone'] ?? null)
                ->setComment($data['comment'] ?? null);
            $this->em->persist($client);
        }
        $device->setClient($client);
        $this->em->flush();

        return $this->json($this->devicePayload($device));
    }

    #[Route('/reports/daily', methods: ['GET'])]
    public function daily(): JsonResponse
    {
        return $this->report(new \DateTimeImmutable('today'));
    }

    #[Route('/reports/monthly', methods: ['GET'])]
    public function monthly(): JsonResponse
    {
        return $this->report(new \DateTimeImmutable('-30 days'));
    }

    private function dashboardPayload(): array
    {
        $today = $this->todayDateParameter();

        return [
            'clients' => $this->em->getRepository(Client::class)->count(['status' => 'active']),
            'devices' => $this->em->getRepository(Device::class)->count([]),
            'unlinkedDevices' => (int) $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\Device d WHERE d.client IS NULL')->getSingleScalarResult(),
            'todayTraffic' => $this->todayTrafficFromRollups($today),
            'todayTrafficBreakdown' => $this->todayTrafficBreakdownFromRollups($today),
            'topDevices' => $this->topDeviceRowsFromRollups($today),
            'topClients' => $this->topClientRowsFromRollups($today),
            'topApps' => $this->topAppsFromRollups($today),
            'topDomains' => $this->topDestinationsFromRollups($today),
            'latestDevices' => $this->latestDevices(),
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function report(\DateTimeImmutable $from): JsonResponse
    {
        $rows = $this->em->createQuery('SELECT IDENTITY(u.device) deviceId, SUM(u.totalBytes) totalBytes FROM App\Entity\DeviceDailyUsage u WHERE u.date >= :from GROUP BY u.device ORDER BY totalBytes DESC')
            ->setParameter('from', $from)
            ->getArrayResult();
        return $this->json($rows);
    }

    private function clientPayload(Client $client): array
    {
        return [
            'id' => $client->getId(),
            'fullName' => $client->getFullName(),
            'roomNumber' => $client->getRoomNumber(),
            'phone' => $client->getPhone(),
            'status' => $client->getStatus(),
            'devices' => array_map(fn (Device $device) => $device->getId(), $client->getDevices()->toArray()),
        ];
    }

    private function devicePayload(Device $device): array
    {
        return [
            'id' => $device->getId(),
            'mac' => $device->getMac(),
            'currentIp' => $device->getCurrentIp(),
            'hostname' => $device->getHostname(),
            'vendor' => $device->getVendor(),
            'vlan' => $device->getVlan(),
            'clientId' => $device->getClient()?->getId(),
            'lastSeenAt' => $device->getLastSeenAt()?->format(DATE_ATOM),
        ];
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
                COALESCE(SUM(f.total_bytes), 0) totalBytes,
                COALESCE(SUM(f.bytes_in), 0) downloadBytes,
                COALESCE(SUM(f.bytes_out), 0) uploadBytes
             FROM device_daily_usage f
             INNER JOIN device d ON d.id = f.device_id
             WHERE f.date = :today
             GROUP BY d.id, d.mac, d.current_ip
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['today' => $today]
        );

        $payload = [];
        foreach ($rows as $row) {
            $device = $this->em->getRepository(Device::class)->find((int) $row['id']);
            if (!$device) {
                continue;
            }

            $payload[] = [
                'id' => $device->getId(),
                'mac' => $device->getMac(),
                'currentIp' => $device->getCurrentIp(),
                'today' => (int) $row['totalBytes'],
                'todayDownload' => (int) $row['downloadBytes'],
                'todayUpload' => (int) $row['uploadBytes'],
            ];
        }

        return $payload;
    }

    private function topClientRowsFromRollups(string $today): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                c.id,
                c.full_name fullName,
                c.room_number roomNumber,
                COALESCE(SUM(f.total_bytes), 0) totalBytes,
                COALESCE(SUM(f.bytes_in), 0) downloadBytes,
                COALESCE(SUM(f.bytes_out), 0) uploadBytes
             FROM device_daily_usage f
             INNER JOIN device d ON d.id = f.device_id
             INNER JOIN client c ON c.id = d.client_id
             WHERE f.date = :today
             GROUP BY c.id, c.full_name, c.room_number
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['today' => $today]
        );

        $payload = [];
        foreach ($rows as $row) {
            $client = $this->em->getRepository(Client::class)->find((int) $row['id']);
            if (!$client) {
                continue;
            }

            $payload[] = [
                'id' => $client->getId(),
                'fullName' => $client->getFullName(),
                'displayName' => $client->getDisplayName(),
                'roomNumber' => $client->getRoomNumber(),
                'today' => (int) $row['totalBytes'],
                'todayDownload' => (int) $row['downloadBytes'],
                'todayUpload' => (int) $row['uploadBytes'],
            ];
        }

        return $payload;
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

    private function todayRangeParameters(): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            'start' => $today->setTime(0, 0)->format('Y-m-d H:i:s'),
            'end' => $today->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
        ];
    }

    private function todayDateParameter(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
