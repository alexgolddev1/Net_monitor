<?php

namespace App\Service;

use App\Entity\TrafficSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NtopngClient
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientResolver $resolver,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly bool $mockNetworkData,
    ) {
    }

    public function syncTraffic(): int
    {
        $hosts = $this->mockNetworkData ? $this->mockHosts() : $this->fetchHosts();
        $count = 0;

        foreach ($hosts as $host) {
            $device = $this->resolver->resolve($host['mac'] ?? null, $host['ip'] ?? null);
            $snapshot = (new TrafficSnapshot())
                ->setDevice($device)
                ->setMac($device->getMac())
                ->setIp($host['ip'])
                ->setBytesIn((int) $host['bytesIn'])
                ->setBytesOut((int) $host['bytesOut'])
                ->setTotalBytes((int) $host['bytesIn'] + (int) $host['bytesOut'])
                ->setPacketsIn($host['packetsIn'] ?? null)
                ->setPacketsOut($host['packetsOut'] ?? null)
                ->setAppsJson($host['apps'] ?? [])
                ->setDestinationsJson($host['destinations'] ?? [])
                ->setSnapshotAt(new \DateTimeImmutable());
            $this->em->persist($snapshot);
            ++$count;
        }

        $this->em->flush();

        return $count;
    }

    private function fetchHosts(): array
    {
        try {
            $url = rtrim($_ENV['NTOPNG_URL'] ?? '', '/').'/lua/rest/v2/get/host/active.lua';
            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$_ENV['NTOPNG_USER'] ?? '', $_ENV['NTOPNG_PASSWORD'] ?? ''],
                'timeout' => 5,
            ]);
            $data = $response->toArray(false);
            $rows = $data['rsp']['data'] ?? $data['data'] ?? [];
            $hosts = [];
            foreach ($rows as $row) {
                $ip = $row['ip'] ?? $row['column_ip'] ?? null;
                if (!$ip) {
                    continue;
                }
                $hosts[] = [
                    'ip' => $ip,
                    'mac' => $row['mac'] ?? null,
                    'bytesIn' => (int) ($row['bytes_rcvd'] ?? $row['bytesIn'] ?? 0),
                    'bytesOut' => (int) ($row['bytes_sent'] ?? $row['bytesOut'] ?? 0),
                    'apps' => [],
                    'destinations' => [],
                ];
            }
            return $hosts;
        } catch (\Throwable $e) {
            $this->logger->error('ntopng sync failed', ['exception' => $e]);
            return [];
        }
    }

    private function mockHosts(): array
    {
        $apps = ['HTTPS', 'DNS', 'YouTube', 'Telegram', 'HTTP', 'Microsoft 365', 'Zoom'];
        $domains = ['google.com', 'youtube.com', 'office.com', 'github.com', 'wikipedia.org', 'zoom.us', 'cloudflare.com'];
        $hosts = [];
        for ($i = 1; $i <= 5; ++$i) {
            shuffle($apps);
            shuffle($domains);
            $in = random_int(20_000_000, 500_000_000);
            $out = random_int(5_000_000, 120_000_000);
            $hosts[] = [
                'mac' => sprintf('AA:%02d:00:00:00:%02d', $i * 10, $i),
                'ip' => sprintf('192.168.%d.%d', $i * 10, $i * 10 + 1),
                'bytesIn' => $in,
                'bytesOut' => $out,
                'packetsIn' => random_int(1000, 40000),
                'packetsOut' => random_int(1000, 20000),
                'apps' => array_map(fn (string $name) => ['name' => $name, 'bytes' => random_int(1_000_000, 80_000_000)], array_slice($apps, 0, 4)),
                'destinations' => array_map(fn (string $domain) => ['domain' => $domain, 'bytes' => random_int(1_000_000, 80_000_000)], array_slice($domains, 0, 5)),
            ];
        }
        return $hosts;
    }
}
