<?php

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\Device;
use App\Entity\DeviceDailyUsage;
use App\Entity\DeviceIpHistory;
use App\Entity\SiteCatalog;
use App\Entity\TrafficSnapshot;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $clients = [
            ['fullName' => 'Иван Петренко', 'room' => '203', 'phone' => '+380501112233'],
            ['fullName' => 'Олена Коваль', 'room' => '101', 'phone' => '+380672223344'],
            ['fullName' => 'Сергій Мельник', 'room' => 'Lab 2', 'phone' => '+380933334455'],
        ];
        $clientEntities = [];
        foreach ($clients as $row) {
            $client = (new Client())->setFullName($row['fullName'])->setRoomNumber($row['room'])->setPhone($row['phone'])->setComment('Demo client');
            $manager->persist($client);
            $clientEntities[] = $client;
        }

        $domains = ['google.com', 'youtube.com', 'office.com', 'github.com', 'wikipedia.org', 'zoom.us', 'cloudflare.com'];
        $catalogTitles = [
            'google.com' => 'Google',
            'youtube.com' => 'YouTube',
            'office.com' => 'Microsoft 365',
            'github.com' => 'GitHub',
            'wikipedia.org' => 'Wikipedia',
            'zoom.us' => 'Zoom',
            'cloudflare.com' => 'Cloudflare',
        ];
        foreach ($domains as $domain) {
            $manager->persist((new SiteCatalog())->setDomain($domain)->setTitle($catalogTitles[$domain] ?? $domain)->setFaviconUrl('https://www.google.com/s2/favicons?domain='.$domain.'&sz=64'));
        }

        $apps = ['HTTPS', 'DNS', 'YouTube', 'Microsoft 365', 'Zoom', 'GitHub'];
        for ($i = 1; $i <= 5; ++$i) {
            $device = (new Device())
                ->setMac(sprintf('AA:%02d:00:00:00:%02d', $i * 10, $i))
                ->setCurrentIp(sprintf('192.168.%d.%d', $i * 10, $i * 10 + 1))
                ->setHostname(['pc-accounting-1', 'printer-203', 'lab-laptop-1', 'phone-reception', 'guest-tablet'][$i - 1])
                ->setVendor(['Dell', 'HP', 'Lenovo', 'Apple', 'Samsung'][$i - 1])
                ->setVlan((string) ($i * 10))
                ->setDeviceName('Demo device '.$i)
                ->setFirstSeenAt(new \DateTimeImmutable('-'.(10 + $i).' days'))
                ->setLastSeenAt(new \DateTimeImmutable('-'.random_int(1, 45).' minutes'));
            if ($i <= 3) {
                $device->setClient($clientEntities[$i - 1]);
            }
            $manager->persist($device);

            $manager->persist((new DeviceIpHistory())
                ->setDevice($device)
                ->setIp($device->getCurrentIp())
                ->setFirstSeenAt(new \DateTimeImmutable('-10 days'))
                ->setLastSeenAt(new \DateTimeImmutable()));

            for ($d = 29; $d >= 0; --$d) {
                $in = random_int(80_000_000, 1_200_000_000);
                $out = random_int(20_000_000, 300_000_000);
                $dayApps = $this->sampleBytes($apps);
                $dayDomains = array_map(fn (array $item) => ['name' => $item['name'], 'bytes' => $item['bytes']], $this->sampleBytes($domains));
                $manager->persist((new DeviceDailyUsage())
                    ->setDevice($device)
                    ->setDate(new \DateTimeImmutable('-'.$d.' days'))
                    ->setBytesIn($in)
                    ->setBytesOut($out)
                    ->setTotalBytes($in + $out)
                    ->setTopAppsJson($dayApps)
                    ->setTopDestinationsJson($dayDomains));
            }

            for ($s = 0; $s < 8; ++$s) {
                $in = random_int(2_000_000, 60_000_000);
                $out = random_int(500_000, 10_000_000);
                $manager->persist((new TrafficSnapshot())
                    ->setDevice($device)
                    ->setMac($device->getMac())
                    ->setIp($device->getCurrentIp())
                    ->setBytesIn($in)
                    ->setBytesOut($out)
                    ->setTotalBytes($in + $out)
                    ->setPacketsIn(random_int(100, 8000))
                    ->setPacketsOut(random_int(100, 3000))
                    ->setAppsJson($this->sampleBytes($apps))
                    ->setDestinationsJson(array_map(fn (array $item) => ['domain' => $item['name'], 'bytes' => $item['bytes']], $this->sampleBytes($domains)))
                    ->setSnapshotAt(new \DateTimeImmutable('-'.random_int(1, 180).' minutes')));
            }
        }

        $manager->flush();
    }

    private function sampleBytes(array $names): array
    {
        shuffle($names);
        return array_map(fn (string $name) => ['name' => $name, 'bytes' => random_int(1_000_000, 90_000_000)], array_slice($names, 0, 5));
    }
}
