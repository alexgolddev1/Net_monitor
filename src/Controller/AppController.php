<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Device;
use App\Entity\DeviceDailyUsage;
use App\Entity\DeviceIpHistory;
use App\Entity\TrafficSnapshot;
use App\Service\TrafficAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class AppController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
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
            'snapshots' => $this->em->getRepository(TrafficSnapshot::class)->findBy(['device' => $device], ['snapshotAt' => 'DESC'], 20),
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
            'snapshots' => $this->clientSnapshots($client, 30),
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
        $today = new \DateTimeImmutable('today');

        return [
            'clientCount' => $clientCount,
            'deviceCount' => $deviceCount,
            'unlinkedCount' => $unlinked,
            'todayTraffic' => (int) $this->em->createQuery('SELECT COALESCE(SUM(u.totalBytes), 0) FROM App\Entity\DeviceDailyUsage u WHERE u.date = :today')->setParameter('today', $today)->getSingleScalarResult(),
            'topDevices' => array_slice($this->deviceRows(), 0, 10),
            'topClients' => array_slice($this->clientRows(), 0, 10),
            'topApps' => $this->topJson('topAppsJson'),
            'topDomains' => $this->topJson('topDestinationsJson'),
            'latestDevices' => $this->em->getRepository(Device::class)->findBy([], ['firstSeenAt' => 'DESC'], 10),
        ];
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
                'lastSnapshot' => $this->em->getRepository(TrafficSnapshot::class)->findOneBy(['device' => $device], ['snapshotAt' => 'DESC']),
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
            ->setParameters(['device' => $device, 'from' => $from])
            ->getSingleScalarResult();
    }

    private function dailyUsage(Device $device, int $days): array
    {
        return $this->em->getRepository(DeviceDailyUsage::class)->createQueryBuilder('u')
            ->andWhere('u.device = :device')
            ->andWhere('u.date >= :from')
            ->setParameters(['device' => $device, 'from' => new \DateTimeImmutable('-'.($days - 1).' days')])
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

    private function clientSnapshots(Client $client, int $limit): array
    {
        $deviceIds = array_map(fn (Device $device) => $device->getId(), $client->getDevices()->toArray());
        if (!$deviceIds) {
            return [];
        }
        return $this->em->getRepository(TrafficSnapshot::class)->createQueryBuilder('s')
            ->andWhere('s.device IN (:ids)')
            ->setParameter('ids', $deviceIds)
            ->orderBy('s.snapshotAt', 'DESC')
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
                $name = $item['name'] ?? $item['domain'] ?? null;
                if (!$name) {
                    continue;
                }
                $totals[$name] = ($totals[$name] ?? 0) + (int) ($item['bytes'] ?? 0);
            }
        }
        arsort($totals);
        return array_slice($totals, 0, 10, true);
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
