<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\DeviceDailyUsage;
use App\Entity\TrafficSnapshot;
use Doctrine\ORM\EntityManagerInterface;

class TrafficAggregator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function aggregateDaily(?\DateTimeImmutable $date = null): int
    {
        $date ??= new \DateTimeImmutable('today');
        $start = $date->setTime(0, 0);
        $end = $date->setTime(23, 59, 59);
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(s.device) deviceId, SUM(s.bytesIn) bytesIn, SUM(s.bytesOut) bytesOut, SUM(s.totalBytes) totalBytes
             FROM App\Entity\TrafficSnapshot s
             WHERE s.device IS NOT NULL AND s.snapshotAt BETWEEN :start AND :end
             GROUP BY s.device'
        )->setParameters(['start' => $start, 'end' => $end])->getArrayResult();

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
                ->setTopAppsJson($this->topFromSnapshots($device, 'appsJson', $start, $end))
                ->setTopDestinationsJson($this->topFromSnapshots($device, 'destinationsJson', $start, $end));
        }

        $this->em->flush();

        return count($rows);
    }

    public function totalsForDevice(Device $device, int $days): int
    {
        $from = new \DateTimeImmutable(sprintf('-%d days', $days - 1));
        return (int) $this->em->createQuery(
            'SELECT COALESCE(SUM(u.totalBytes), 0) FROM App\Entity\DeviceDailyUsage u WHERE u.device = :device AND u.date >= :from'
        )->setParameters(['device' => $device, 'from' => $from])->getSingleScalarResult();
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
            ->setParameters(['device' => $device, 'start' => $start, 'end' => $end])
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $totals = [];
        foreach ($snapshots as $snapshot) {
            $items = $field === 'appsJson' ? $snapshot->getAppsJson() : $snapshot->getDestinationsJson();
            foreach ($items ?? [] as $item) {
                $key = $item['name'] ?? $item['domain'] ?? null;
                if (!$key) {
                    continue;
                }
                $totals[$key] = ($totals[$key] ?? 0) + (int) ($item['bytes'] ?? 0);
            }
        }
        arsort($totals);

        return array_map(fn ($key, $bytes) => ['name' => $key, 'bytes' => $bytes], array_keys(array_slice($totals, 0, 10, true)), array_values(array_slice($totals, 0, 10, true)));
    }
}
