<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    )
    {
    }

    #[Route('/dashboard', methods: ['GET'], name: 'api_dashboard')]
    public function dashboard(): JsonResponse
    {
        return $this->json($this->dashboardPayload());
    }

    #[Route('/dashboard/stream', methods: ['GET'], name: 'api_dashboard_stream')]
    public function dashboardStream(): StreamedResponse
    {
        $response = new StreamedResponse(function (): void {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            @set_time_limit(0);

            echo "retry: 15000\n\n";
            @ob_flush();
            @flush();

            while (!connection_aborted()) {
                $this->emitDashboardEvent();
                @ob_flush();
                @flush();

                if (connection_aborted()) {
                    break;
                }

                sleep(15);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
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
        return [
            'clients' => $this->em->getRepository(Client::class)->count(['status' => 'active']),
            'devices' => $this->em->getRepository(Device::class)->count([]),
            'unlinkedDevices' => (int) $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\Device d WHERE d.client IS NULL')->getSingleScalarResult(),
            'todayTraffic' => $this->todayTrafficFromFlows(),
            'topDevices' => $this->topDeviceRowsFromFlows(),
            'topClients' => $this->topClientRowsFromFlows(),
            'topApps' => $this->topAppsFromFlows(),
            'topDomains' => $this->topDestinationsFromFlows(),
            'latestDevices' => $this->latestDevices(),
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function emitDashboardEvent(): void
    {
        echo 'event: dashboard' . "\n";
        echo 'data: '.json_encode($this->dashboardPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
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

    private function todayTrafficFromFlows(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(bytes), 0) FROM network_flow WHERE received_at BETWEEN :start AND :end',
            $this->todayRangeParameters()
        );
    }

    private function topDeviceRowsFromFlows(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT d.id, COALESCE(SUM(f.bytes), 0) totalBytes
             FROM network_flow f
             INNER JOIN device d ON d.id = f.device_id
             WHERE f.received_at BETWEEN :start AND :end
             GROUP BY d.id
             ORDER BY totalBytes DESC
             LIMIT 10',
            $this->todayRangeParameters()
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
                'month' => $this->usageTotal($device, 30),
            ];
        }

        return $payload;
    }

    private function topClientRowsFromFlows(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT c.id, COALESCE(SUM(f.bytes), 0) totalBytes
             FROM network_flow f
             INNER JOIN client c ON c.id = f.client_id
             WHERE f.received_at BETWEEN :start AND :end
             GROUP BY c.id
             ORDER BY totalBytes DESC
             LIMIT 10',
            $this->todayRangeParameters()
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
            ];
        }

        return $payload;
    }

    private function topAppsFromFlows(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                app_name appName,
                COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE received_at BETWEEN :start AND :end
               AND app_name IS NOT NULL
               AND LOWER(app_name) <> \'unknown\'
             GROUP BY appName
             ORDER BY totalBytes DESC
             LIMIT 10',
            $this->todayRangeParameters()
        );

        $apps = [];
        foreach ($rows as $row) {
            $label = $this->normalizeAppName(isset($row['appName']) ? (string) $row['appName'] : null);
            $apps[$label] = ($apps[$label] ?? 0) + (int) $row['totalBytes'];
        }

        if ($apps === []) {
            $unknown = (int) $this->em->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(bytes), 0) FROM network_flow WHERE received_at BETWEEN :start AND :end AND (app_name IS NULL OR LOWER(app_name) = \'unknown\')',
                $this->todayRangeParameters()
            );
            if ($unknown > 0) {
                $apps['Unknown'] = $unknown;
            }
        }

        return $apps;
    }

    private function topDestinationsFromFlows(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                domain,
                COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE received_at BETWEEN :start AND :end
               AND domain IS NOT NULL
               AND TRIM(domain) <> \'\'
               AND LOWER(TRIM(domain)) <> \'unknown\'
             GROUP BY domain
             ORDER BY totalBytes DESC
             LIMIT 10',
            $this->todayRangeParameters()
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

    private function usageTotal(Device $device, int $days): int
    {
        $from = new \DateTimeImmutable(sprintf('-%d days', $days - 1));

        return (int) $this->em->createQuery('SELECT COALESCE(SUM(u.totalBytes), 0) FROM App\Entity\DeviceDailyUsage u WHERE u.device = :device AND u.date >= :from')
            ->setParameter('device', $device)
            ->setParameter('from', $from)
            ->getSingleScalarResult();
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
}
