<?php

namespace App\Service;

use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MikroTikClient
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientResolver $resolver,
        private readonly LoggerInterface $logger,
        private readonly bool $mockNetworkData,
    ) {
    }

    public function syncLeases(): int
    {
        $leases = $this->mockNetworkData ? $this->mockLeases() : $this->fetchLeases();
        $count = 0;

        foreach ($leases as $lease) {
            $mac = strtoupper((string) ($lease['mac'] ?? ''));
            $ip = (string) ($lease['ip'] ?? '');
            if (!$mac || !$ip) {
                continue;
            }

            $device = $this->resolver->resolve($mac, $ip);
            $device
                ->setHostname($lease['hostname'] ?? $device->getHostname())
                ->setVendor($lease['vendor'] ?? $device->getVendor())
                ->setVlan($lease['vlan'] ?? $device->getVlan())
                ->setDeviceName($lease['comment'] ?? $device->getDeviceName());
            $this->resolver->recordIp($device, $ip);
            ++$count;
        }

        $this->em->flush();

        return $count;
    }

    /**
     * Real RouterOS API support can be extended here. MVP keeps failures non-fatal.
     */
    private function fetchLeases(): array
    {
        try {
            $host = $_ENV['MIKROTIK_HOST'] ?? '';
            $port = (int) ($_ENV['MIKROTIK_PORT'] ?? 8728);
            $socket = @fsockopen($host, $port, $errno, $errstr, 3);
            if (!$socket) {
                $this->logger->warning('MikroTik API unavailable', ['error' => $errstr, 'code' => $errno]);
                return [];
            }
            fclose($socket);
            $this->logger->info('MikroTik API port is reachable; RouterOS query adapter is not configured in MVP');
        } catch (\Throwable $e) {
            $this->logger->error('MikroTik sync failed', ['exception' => $e]);
        }

        return [];
    }

    private function mockLeases(): array
    {
        return [
            ['mac' => 'AA:10:00:00:00:01', 'ip' => '192.168.10.11', 'hostname' => 'pc-accounting-1', 'vendor' => 'Dell', 'vlan' => '10', 'comment' => 'Accounting PC'],
            ['mac' => 'AA:10:00:00:00:02', 'ip' => '192.168.10.12', 'hostname' => 'printer-203', 'vendor' => 'HP', 'vlan' => '10', 'comment' => 'Office printer'],
            ['mac' => 'AA:20:00:00:00:03', 'ip' => '192.168.20.21', 'hostname' => 'lab-laptop-1', 'vendor' => 'Lenovo', 'vlan' => '20', 'comment' => 'Lab laptop'],
            ['mac' => 'AA:30:00:00:00:04', 'ip' => '192.168.30.31', 'hostname' => 'phone-reception', 'vendor' => 'Apple', 'vlan' => '30', 'comment' => 'Reception phone'],
            ['mac' => 'AA:40:00:00:00:05', 'ip' => '192.168.40.41', 'hostname' => 'guest-tablet', 'vendor' => 'Samsung', 'vlan' => '40', 'comment' => 'Guest device'],
        ];
    }
}
