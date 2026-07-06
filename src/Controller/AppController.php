<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Device;
use App\Entity\DeviceDailyUsage;
use App\Entity\DeviceIpHistory;
use App\Entity\NetworkFlow;
use App\Service\ApplicationLabelResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class AppController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApplicationLabelResolver $applicationLabelResolver,
    )
    {
    }

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->redirectToRoute('dashboard');
    }

    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(): Response
    {
        return $this->render('dashboard/index.html.twig', $this->dashboardData());
    }

    #[Route('/devices', name: 'devices')]
    public function devices(Request $request): Response
    {
        $hideLinked = $request->query->getBoolean('hide_linked');

        return $this->render('devices/index.html.twig', [
            'devices' => $this->deviceRows($hideLinked),
            'clients' => $this->em->getRepository(Client::class)->findBy(['status' => 'active'], ['fullName' => 'ASC']),
            'hideLinked' => $hideLinked,
        ]);
    }

    #[Route('/devices/{id}', name: 'device_show', requirements: ['id' => '\d+'])]
    public function device(Device $device): Response
    {
        return $this->render('devices/show.html.twig', [
            'device' => $device,
            'ipHistory' => $this->em->getRepository(DeviceIpHistory::class)->findBy(['device' => $device], ['lastSeenAt' => 'DESC'], 20),
            'flows' => $this->em->getRepository(NetworkFlow::class)->findBy(['device' => $device], ['receivedAt' => 'DESC'], 20),
            'recentDomains' => $this->recentDomainsForDevice($device),
            'topDomainsToday' => $this->topDomainsForDevice($device, 10),
            'topAppsToday' => $this->topAppsForDevice($device, 10),
            'recentActivity' => $this->recentActivityForDevice($device, 20),
            'todayBytes' => $this->usageTotal($device, 1),
            'monthBytes' => $this->usageTotal($device, 30),
            'daily' => $this->dailyUsage($device, 30),
        ]);
    }

    #[Route('/devices/{id}/link-client', name: 'device_link_client', methods: ['POST'])]
    public function linkDevice(Device $device, Request $request): Response
    {
        $clientId = (int) $request->request->get('client_id');
        $client = $clientId ? $this->em->getRepository(Client::class)->find($clientId) : null;

        if (!$client) {
            $client = (new Client())
                ->setFullName($request->request->get('full_name') ?: null)
                ->setRoomNumber($request->request->get('room_number') ?: null)
                ->setPhone($request->request->get('phone') ?: null);
            $this->em->persist($client);
        }

        $device
            ->setClient($client)
            ->setComment($this->normalizedInput($request->request->get('comment')));
        $this->em->flush();
        $this->addFlash('success', 'Device linked.');

        return $this->redirectToRoute('devices');
    }

    #[Route('/clients', name: 'clients')]
    public function clients(): Response
    {
        return $this->render('clients/index.html.twig', ['clients' => $this->clientRows()]);
    }

    #[Route('/clients', name: 'client_create', methods: ['POST'])]
    public function createClient(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('client_create', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $client = (new Client())
            ->setFullName($this->normalizedInput($request->request->get('full_name')))
            ->setRoomNumber($this->normalizedInput($request->request->get('room_number')))
            ->setPhone($this->normalizedInput($request->request->get('phone')))
            ->setComment($this->normalizedInput($request->request->get('comment')));

        $this->em->persist($client);
        $this->em->flush();
        $this->addFlash('success', 'Client created.');

        return $this->redirectToRoute('clients');
    }

    #[Route('/clients/{id}/edit', name: 'client_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateClient(Client $client, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('client_update_'.$client->getId(), (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $client
            ->setFullName($this->normalizedInput($request->request->get('full_name')))
            ->setRoomNumber($this->normalizedInput($request->request->get('room_number')))
            ->setPhone($this->normalizedInput($request->request->get('phone')))
            ->setComment($this->normalizedInput($request->request->get('comment')));

        if ($status = $this->normalizedInput($request->request->get('status'))) {
            $client->setStatus($status);
        }

        $this->em->flush();
        $this->addFlash('success', 'Client updated.');

        return $this->redirectToRoute('clients');
    }

    #[Route('/clients/{id}/delete', name: 'client_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteClient(Client $client, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('client_delete_'.$client->getId(), (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->em->remove($client);
        $this->em->flush();
        $this->addFlash('success', 'Client deleted.');

        return $this->redirectToRoute('clients');
    }

    #[Route('/clients/{id}', name: 'client_show', requirements: ['id' => '\d+'])]
    public function client(Client $client): Response
    {
        return $this->render('clients/show.html.twig', [
            'client' => $client,
            'devices' => $client->getDevices(),
            'daily' => $this->clientDailyUsage($client, 30),
            'flows' => $this->clientFlows($client, 30),
            'recentDomains' => $this->recentDomainsForClient($client),
            'topDomainsToday' => $this->topDomainsForClient($client, 10),
            'topAppsToday' => $this->topAppsForClient($client, 10),
            'recentActivity' => $this->recentActivityForClient($client, 20),
        ]);
    }

    #[Route('/reports', name: 'reports')]
    public function reports(): Response
    {
        return $this->render('reports/index.html.twig', [
            'daily' => $this->reportRows(new \DateTimeImmutable('today')),
            'monthly' => $this->reportRows(new \DateTimeImmutable('-30 days')),
        ]);
    }

    #[Route('/reports/daily.csv', name: 'reports_daily_csv')]
    public function dailyCsv(): StreamedResponse
    {
        return $this->csvResponse('daily-report.csv', $this->reportRows(new \DateTimeImmutable('today')));
    }

    #[Route('/reports/monthly.csv', name: 'reports_monthly_csv')]
    public function monthlyCsv(): StreamedResponse
    {
        return $this->csvResponse('monthly-report.csv', $this->reportRows(new \DateTimeImmutable('-30 days')));
    }

    private function dashboardData(): array
    {
        $clientCount = $this->em->getRepository(Client::class)->count(['status' => 'active']);
        $deviceCount = $this->em->getRepository(Device::class)->count([]);
        $unlinked = $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\Device d WHERE d.client IS NULL')->getSingleScalarResult();

        return [
            'clientCount' => $clientCount,
            'deviceCount' => $deviceCount,
            'unlinkedCount' => $unlinked,
            'todayTraffic' => $this->todayTrafficFromFlows(),
            'topDevices' => $this->topDeviceRowsFromFlows(),
            'topClients' => $this->topClientRowsFromFlows(),
            'topApps' => $this->topAppsFromFlows(),
            'topDomains' => $this->topDestinationsFromFlows(),
            'latestDevices' => $this->em->getRepository(Device::class)->findBy([], ['firstSeenAt' => 'DESC'], 10),
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

        return array_values(array_filter(array_map(function (array $row): ?array {
            $device = $this->em->getRepository(Device::class)->find((int) $row['id']);

            return $device ? ['device' => $device, 'today' => (int) $row['totalBytes'], 'month' => $this->usageTotal($device, 30)] : null;
        }, $rows)));
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

        return array_values(array_filter(array_map(function (array $row): ?array {
            $client = $this->em->getRepository(Client::class)->find((int) $row['id']);

            return $client ? ['client' => $client, 'today' => (int) $row['totalBytes']] : null;
        }, $rows)));
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

        if (count($apps) < 10) {
            $unknown = (int) $this->em->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(bytes), 0) FROM network_flow WHERE received_at BETWEEN :start AND :end AND (app_name IS NULL OR LOWER(app_name) = \'unknown\')',
                $this->todayRangeParameters()
            );
            if ($unknown > 0 && $apps === []) {
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

    private function recentDomainsForDevice(Device $device, int $limit = 20): array
    {
        return $this->recentDomains($this->flowDeviceFilter($device), $limit);
    }

    private function recentDomainsForClient(Client $client, int $limit = 20): array
    {
        return $this->recentDomains($this->flowClientFilter($client), $limit);
    }

    private function topDomainsForDevice(Device $device, int $limit = 10): array
    {
        return $this->topDomains($this->flowDeviceFilter($device), $limit);
    }

    private function topDomainsForClient(Client $client, int $limit = 10): array
    {
        return $this->topDomains($this->flowClientFilter($client), $limit);
    }

    private function topAppsForDevice(Device $device, int $limit = 10): array
    {
        return $this->topApps($this->flowDeviceFilter($device), $limit);
    }

    private function topAppsForClient(Client $client, int $limit = 10): array
    {
        return $this->topApps($this->flowClientFilter($client), $limit);
    }

    private function recentActivityForDevice(Device $device, int $limit = 20): array
    {
        return $this->recentActivity($this->flowDeviceFilter($device), $limit);
    }

    private function recentActivityForClient(Client $client, int $limit = 20): array
    {
        return $this->recentActivity($this->flowClientFilter($client), $limit);
    }

    private function recentDomains(array $filter, int $limit): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT domain, MAX(received_at) lastSeenAt, COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE '.$filter['where'].' AND received_at >= :from AND domain IS NOT NULL AND TRIM(domain) <> \'\'
               AND LOWER(TRIM(domain)) <> \'unknown\'
             GROUP BY domain
             ORDER BY lastSeenAt DESC
             LIMIT '.$limit,
            $filter['params'] + ['from' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]
        );

        return array_values(array_map(fn (array $row): array => [
            'domain' => (string) $row['domain'],
            'lastSeenAt' => $row['lastSeenAt'] ?? null,
            'bytes' => (int) $row['totalBytes'],
        ], $rows));
    }

    private function topDomains(array $filter, int $limit): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT domain, COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE '.$filter['where'].' AND received_at BETWEEN :start AND :end AND domain IS NOT NULL AND TRIM(domain) <> \'\'
               AND LOWER(TRIM(domain)) <> \'unknown\'
             GROUP BY domain
             ORDER BY totalBytes DESC
             LIMIT '.$limit,
            $filter['params'] + $this->todayRangeParameters()
        );

        return array_values(array_map(fn (array $row): array => [
            'domain' => (string) $row['domain'],
            'bytes' => (int) $row['totalBytes'],
        ], $rows));
    }

    private function topApps(array $filter, int $limit): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT app_name appName, COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE '.$filter['where'].' AND received_at BETWEEN :start AND :end
               AND app_name IS NOT NULL
               AND LOWER(app_name) <> \'unknown\'
             GROUP BY appName
             ORDER BY totalBytes DESC
             LIMIT '.$limit,
            $filter['params'] + $this->todayRangeParameters()
        );

        $apps = [];
        foreach ($rows as $row) {
            $label = $this->normalizeAppName(isset($row['appName']) ? (string) $row['appName'] : null);
            $apps[$label] = ($apps[$label] ?? 0) + (int) $row['totalBytes'];
        }

        if ($apps === []) {
            $unknown = (int) $this->em->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(bytes), 0) FROM network_flow WHERE '.$filter['where'].' AND received_at BETWEEN :start AND :end AND (app_name IS NULL OR LOWER(app_name) = \'unknown\')',
                $filter['params'] + $this->todayRangeParameters()
            );
            if ($unknown > 0) {
                $apps['Unknown'] = $unknown;
            }
        }

        return $apps;
    }

    private function recentActivity(array $filter, int $limit): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT received_at, direction, domain, app_name, bytes, src_ip, dst_ip, src_port, dst_port
             FROM network_flow
             WHERE '.$filter['where'].'
             ORDER BY received_at DESC
             LIMIT '.$limit,
            $filter['params']
        );

        return array_values(array_map(function (array $row): array {
            $label = $this->normalizeActivityLabel(
                isset($row['domain']) ? (string) $row['domain'] : null,
                isset($row['app_name']) ? (string) $row['app_name'] : null,
                (string) ($row['direction'] ?? ''),
                isset($row['src_ip']) ? (string) $row['src_ip'] : null,
                isset($row['dst_ip']) ? (string) $row['dst_ip'] : null,
                $row['src_port'] ?? null,
                $row['dst_port'] ?? null
            );

            return [
                'receivedAt' => $row['received_at'] ?? null,
                'direction' => $this->directionLabel((string) ($row['direction'] ?? '')),
                'label' => $label,
                'bytes' => (int) ($row['bytes'] ?? 0),
                'src' => $this->formatFlowEndpoint($row['src_ip'] ?? null, $row['src_port'] ?? null),
                'dst' => $this->formatFlowEndpoint($row['dst_ip'] ?? null, $row['dst_port'] ?? null),
            ];
        }, $rows));
    }

    private function flowDeviceFilter(Device $device): array
    {
        return [
            'where' => 'device_id = :deviceId',
            'params' => ['deviceId' => $device->getId()],
        ];
    }

    private function flowClientFilter(Client $client): array
    {
        $deviceIds = array_values(array_filter(array_map(
            fn (Device $device): ?int => $device->getId(),
            $client->getDevices()->toArray()
        )));

        if ($deviceIds === []) {
            return [
                'where' => 'client_id = :clientId',
                'params' => ['clientId' => $client->getId()],
            ];
        }

        return [
            'where' => '(client_id = :clientId OR device_id IN ('.implode(',', array_map('intval', $deviceIds)).'))',
            'params' => ['clientId' => $client->getId()],
        ];
    }

    private function normalizeAppName(?string $appName): string
    {
        $appName = $appName !== null ? trim($appName) : '';

        return $appName === '' || is_numeric($appName) ? 'Unknown' : $appName;
    }

    private function normalizeActivityLabel(
        ?string $domain,
        ?string $appName,
        string $direction,
        ?string $srcIp,
        ?string $dstIp,
        mixed $srcPort,
        mixed $dstPort,
    ): string
    {
        $domain = $domain !== null ? trim($domain) : '';
        if ($domain !== '' && strcasecmp($domain, 'unknown') !== 0) {
            return $domain;
        }

        $appName = $this->normalizeAppName($appName);
        if ($appName !== 'Unknown') {
            return $appName;
        }

        return match ($direction) {
            'upload' => $this->formatFlowEndpoint($dstIp, $dstPort),
            'download' => $this->formatFlowEndpoint($srcIp, $srcPort),
            default => $this->formatFlowEndpoint($dstIp ?: $srcIp, $dstIp ? $dstPort : $srcPort),
        };
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

    private function formatFlowEndpoint(mixed $ip, mixed $port): string
    {
        $ip = is_string($ip) ? $ip : '';
        $port = is_numeric($port) ? (int) $port : null;

        if ($ip === '') {
            return '-';
        }

        return $port !== null ? sprintf('%s:%d', $ip, $port) : $ip;
    }

    private function deviceRows(bool $hideLinked = false): array
    {
        $devices = $this->em->getRepository(Device::class)->findBy([], ['lastSeenAt' => 'DESC']);
        $todayTotals = $this->usageTotalsByDeviceFromFlows(1);
        $monthTotals = $this->usageTotalsByDeviceFromFlows(30);
        $rows = [];
        foreach ($devices as $device) {
            if ($hideLinked && $device->getClient() !== null) {
                continue;
            }

            $deviceId = $device->getId();
            $rows[] = [
                'device' => $device,
                'today' => $deviceId !== null ? ($todayTotals[$deviceId] ?? 0) : 0,
                'month' => $deviceId !== null ? ($monthTotals[$deviceId] ?? 0) : 0,
            ];
        }
        usort($rows, fn ($a, $b) => $b['today'] <=> $a['today']);
        return $rows;
    }

    private function clientRows(): array
    {
        $clients = $this->em->getRepository(Client::class)->findBy(['status' => 'active'], ['fullName' => 'ASC']);
        $rows = [];
        foreach ($clients as $client) {
            $today = 0;
            $month = 0;
            $lastSeen = null;
            foreach ($client->getDevices() as $device) {
                $today += $this->usageTotal($device, 1);
                $month += $this->usageTotal($device, 30);
                if ($device->getLastSeenAt() && (!$lastSeen || $device->getLastSeenAt() > $lastSeen)) {
                    $lastSeen = $device->getLastSeenAt();
                }
            }
            $rows[] = ['client' => $client, 'today' => $today, 'month' => $month, 'lastSeen' => $lastSeen, 'deviceCount' => count($client->getDevices())];
        }
        usort($rows, fn ($a, $b) => $b['today'] <=> $a['today']);
        return $rows;
    }

    private function usageTotal(Device $device, int $days): int
    {
        if ($device->getId() === null) {
            return 0;
        }

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(bytes), 0)
             FROM network_flow
             WHERE device_id = :deviceId
               AND received_at BETWEEN :start AND :end',
            ['deviceId' => $device->getId()] + $this->trafficRangeParameters($days)
        );
    }

    /**
     * @return array<int, int>
     */
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

    private function dailyUsage(Device $device, int $days): array
    {
        return $this->em->getRepository(DeviceDailyUsage::class)->createQueryBuilder('u')
            ->andWhere('u.device = :device')
            ->andWhere('u.date >= :from')
            ->setParameter('device', $device)
            ->setParameter('from', new \DateTimeImmutable('-'.($days - 1).' days'))
            ->orderBy('u.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function clientDailyUsage(Client $client, int $days): array
    {
        $rows = [];
        $start = (new \DateTimeImmutable('today'))->modify('-'.($days - 1).' days');
        $end = (new \DateTimeImmutable('today'))->setTime(23, 59, 59);

        for ($day = 0; $day < $days; ++$day) {
            $rows[$start->modify('+'.$day.' days')->format('Y-m-d')] = 0;
        }

        $deviceIds = array_values(array_filter(array_map(
            fn (Device $device): ?int => $device->getId(),
            $client->getDevices()->toArray()
        )));

        if ($deviceIds === []) {
            return $rows;
        }

        $flowRows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT DATE(received_at) usageDate, COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE (client_id = :clientId OR device_id IN ('.implode(',', array_map('intval', $deviceIds)).'))
               AND received_at BETWEEN :start AND :end
             GROUP BY DATE(received_at)
             ORDER BY usageDate ASC',
            [
                'clientId' => $client->getId(),
                'start' => $start->setTime(0, 0)->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        );

        foreach ($flowRows as $row) {
            $key = (string) $row['usageDate'];
            if (array_key_exists($key, $rows)) {
                $rows[$key] = (int) $row['totalBytes'];
            }
        }

        if (array_sum($rows) > 0) {
            return $rows;
        }

        foreach ($client->getDevices() as $device) {
            foreach ($this->dailyUsage($device, $days) as $usage) {
                $key = $usage->getDate()->format('Y-m-d');
                $rows[$key] = ($rows[$key] ?? 0) + $usage->getTotalBytes();
            }
        }
        ksort($rows);
        return $rows;
    }

    private function clientFlows(Client $client, int $limit): array
    {
        $filter = $this->flowClientFilter($client);
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id FROM network_flow WHERE '.$filter['where'].' ORDER BY received_at DESC LIMIT '.$limit,
            $filter['params']
        );
        $ids = array_map(fn (array $row): int => (int) $row['id'], $rows);

        if ($ids === []) {
            return [];
        }

        return $this->em->getRepository(NetworkFlow::class)->createQueryBuilder('f')
            ->andWhere('f.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('f.receivedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function topJson(string $field): array
    {
        $usages = $this->em->getRepository(DeviceDailyUsage::class)->findBy([], ['date' => 'DESC'], 200);
        $totals = [];
        foreach ($usages as $usage) {
            $items = $field === 'topAppsJson' ? $usage->getTopAppsJson() : $usage->getTopDestinationsJson();
            foreach ($items ?? [] as $item) {
                $name = $this->normalizeTopLabel($item);
                if (!$name) {
                    continue;
                }
                $totals[$name] = ($totals[$name] ?? 0) + (int) ($item['bytes'] ?? 0);
            }
        }
        arsort($totals);
        return array_slice($totals, 0, 10, true);
    }

    private function normalizeTopLabel(array $item): ?string
    {
        $label = $this->applicationLabelResolver->labelForItem($item);

        return $label === 'Unknown' ? null : $label;
    }

    private function todayRangeParameters(): array
    {
        return $this->trafficRangeParameters(1);
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

    private function reportRows(\DateTimeImmutable $from): array
    {
        return $this->em->createQuery(
            'SELECT c.id, c.fullName, c.roomNumber, SUM(u.totalBytes) totalBytes
             FROM App\Entity\DeviceDailyUsage u
             JOIN u.device d
             LEFT JOIN d.client c
             WHERE u.date >= :from
             GROUP BY c.id, c.fullName, c.roomNumber
             ORDER BY totalBytes DESC'
        )->setParameter('from', $from)->getArrayResult();
    }

    private function csvResponse(string $filename, array $rows): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['client', 'room', 'total_bytes']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['fullName'] ?: 'Unlinked', $row['roomNumber'] ?? '', $row['totalBytes'] ?? 0]);
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        return $response;
    }

    private function normalizedInput(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
