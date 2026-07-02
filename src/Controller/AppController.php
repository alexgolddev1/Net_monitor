<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Device;
use App\Entity\DeviceDailyUsage;
use App\Entity\DeviceIpHistory;
use App\Entity\NetworkFlow;
use App\Service\ApplicationLabelResolver;
use App\Service\TrafficAggregator;
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
    public function devices(): Response
    {
        return $this->render('devices/index.html.twig', [
            'devices' => $this->deviceRows(),
            'clients' => $this->em->getRepository(Client::class)->findBy(['status' => 'active'], ['fullName' => 'ASC']),
        ]);
    }

    #[Route('/devices/{id}', name: 'device_show', requirements: ['id' => '\d+'])]
    public function device(Device $device, TrafficAggregator $aggregator): Response
    {
        return $this->render('devices/show.html.twig', [
            'device' => $device,
            'ipHistory' => $this->em->getRepository(DeviceIpHistory::class)->findBy(['device' => $device], ['lastSeenAt' => 'DESC'], 20),
            'flows' => $this->em->getRepository(NetworkFlow::class)->findBy(['device' => $device], ['receivedAt' => 'DESC'], 20),
            'todayBytes' => $aggregator->totalsForDevice($device, 1),
            'monthBytes' => $aggregator->totalsForDevice($device, 30),
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
                ->setPhone($request->request->get('phone') ?: null)
                ->setComment($request->request->get('comment') ?: null);
            $this->em->persist($client);
        }

        $device->setClient($client);
        $this->em->flush();
        $this->addFlash('success', 'Device linked.');

        return $this->redirectToRoute('devices');
    }

    #[Route('/clients', name: 'clients')]
    public function clients(): Response
    {
        return $this->render('clients/index.html.twig', ['clients' => $this->clientRows()]);
    }

    #[Route('/clients/{id}', name: 'client_show', requirements: ['id' => '\d+'])]
    public function client(Client $client): Response
    {
        return $this->render('clients/show.html.twig', [
            'client' => $client,
            'devices' => $client->getDevices(),
            'daily' => $this->clientDailyUsage($client, 30),
            'flows' => $this->clientFlows($client, 30),
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
                CASE WHEN direction = :download THEN src_ip ELSE dst_ip END remoteIp,
                CASE WHEN direction = :download THEN src_port ELSE dst_port END remotePort,
                protocol,
                COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE received_at BETWEEN :start AND :end
             GROUP BY remoteIp, remotePort, protocol
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['download' => 'download'] + $this->todayRangeParameters()
        );

        $apps = [];
        foreach ($rows as $row) {
            $label = $this->applicationLabelResolver->resolveFromFlow(
                isset($row['protocol']) ? (int) $row['protocol'] : null,
                isset($row['remotePort']) ? (int) $row['remotePort'] : null,
                isset($row['remoteIp']) ? (string) $row['remoteIp'] : null
            );
            $apps[$label] = ($apps[$label] ?? 0) + (int) $row['totalBytes'];
        }

        arsort($apps);

        return $apps;
    }

    private function topDestinationsFromFlows(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT
                CASE WHEN direction = :download THEN src_ip ELSE dst_ip END remoteIp,
                COALESCE(SUM(bytes), 0) totalBytes
             FROM network_flow
             WHERE received_at BETWEEN :start AND :end
             GROUP BY remoteIp
             HAVING remoteIp IS NOT NULL
             ORDER BY totalBytes DESC
             LIMIT 10',
            ['download' => 'download'] + $this->todayRangeParameters()
        );

        $destinations = [];
        foreach ($rows as $row) {
            $label = $this->applicationLabelResolver->domainForIp(isset($row['remoteIp']) ? (string) $row['remoteIp'] : null) ?? 'Unknown';
            $destinations[$label] = ($destinations[$label] ?? 0) + (int) $row['totalBytes'];
        }

        arsort($destinations);

        return $destinations;
    }

    private function deviceRows(): array
    {
        $devices = $this->em->getRepository(Device::class)->findBy([], ['lastSeenAt' => 'DESC']);
        $rows = [];
        foreach ($devices as $device) {
            $rows[] = [
                'device' => $device,
                'today' => $this->usageTotal($device, 1),
                'month' => $this->usageTotal($device, 30),
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
        $from = new \DateTimeImmutable(sprintf('-%d days', $days - 1));
        return (int) $this->em->createQuery('SELECT COALESCE(SUM(u.totalBytes), 0) FROM App\Entity\DeviceDailyUsage u WHERE u.device = :device AND u.date >= :from')
            ->setParameter('device', $device)
            ->setParameter('from', $from)
            ->getSingleScalarResult();
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
        return $this->em->getRepository(NetworkFlow::class)->createQueryBuilder('f')
            ->andWhere('f.client = :client')
            ->setParameter('client', $client)
            ->orderBy('f.receivedAt', 'DESC')
            ->setMaxResults($limit)
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
        $today = new \DateTimeImmutable('today');

        return [
            'start' => $today->setTime(0, 0)->format('Y-m-d H:i:s'),
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
}
