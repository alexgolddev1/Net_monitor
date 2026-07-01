<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\DeviceIpHistory;
use Doctrine\ORM\EntityManagerInterface;

class ClientResolver
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function resolve(?string $mac, ?string $ip): Device
    {
        $repo = $this->em->getRepository(Device::class);
        $device = $mac ? $repo->findOneBy(['mac' => strtoupper($mac)]) : null;
        $device ??= $ip ? $repo->findOneBy(['currentIp' => $ip]) : null;

        if (!$device) {
            $device = (new Device())
                ->setMac($mac ? strtoupper($mac) : $this->pseudoMacFromIp($ip ?: '0.0.0.0'))
                ->setCurrentIp($ip)
                ->setFirstSeenAt(new \DateTimeImmutable());
            $this->em->persist($device);
        }

        if ($ip && $device->getCurrentIp() !== $ip) {
            $this->recordIp($device, $ip);
            $device->setCurrentIp($ip);
        }

        $device->setLastSeenAt(new \DateTimeImmutable());

        return $device;
    }

    public function recordIp(Device $device, string $ip): void
    {
        $now = new \DateTimeImmutable();
        $history = $this->em->getRepository(DeviceIpHistory::class)->findOneBy(['device' => $device, 'ip' => $ip]);
        if (!$history) {
            $history = (new DeviceIpHistory())
                ->setDevice($device)
                ->setIp($ip)
                ->setFirstSeenAt($now);
            $this->em->persist($history);
        }
        $history->setLastSeenAt($now);
    }

    private function pseudoMacFromIp(string $ip): string
    {
        $hash = strtoupper(substr(md5($ip), 0, 12));
        return implode(':', str_split($hash, 2));
    }
}
