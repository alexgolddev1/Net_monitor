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

        $this->em->flush();

        return count($rows);
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
               AND LOWER(domain) <> \'unknown\'
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
