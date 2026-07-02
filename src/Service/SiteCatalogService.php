<?php

namespace App\Service;

use App\Entity\SiteCatalog;
use App\Entity\TrafficSnapshot;
use Doctrine\ORM\EntityManagerInterface;

class SiteCatalogService
{
    private const DOMAIN_TITLES = [
        'google.com' => 'Google',
        'youtube.com' => 'YouTube',
        'office.com' => 'Microsoft 365',
        'github.com' => 'GitHub',
        'wikipedia.org' => 'Wikipedia',
        'zoom.us' => 'Zoom',
        'cloudflare.com' => 'Cloudflare',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $faviconProvider,
    ) {
    }

    public function enrichFromSnapshots(): int
    {
        $snapshots = $this->em->getRepository(TrafficSnapshot::class)->createQueryBuilder('s')
            ->orderBy('s.snapshotAt', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
        $count = 0;

        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->getDestinationsJson() ?? [] as $destination) {
                $domain = $destination['domain'] ?? null;
                if (!$domain) {
                    continue;
                }
                if ($this->ensureDomain($domain)) {
                    ++$count;
                }
            }
        }

        $this->em->flush();

        return $count;
    }

    public function ensureDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if (!$domain) {
            return false;
        }
        $site = $this->em->getRepository(SiteCatalog::class)->findOneBy(['domain' => $domain]);
        if ($site) {
            return false;
        }

        $favicon = $this->faviconProvider === 'duckduckgo'
            ? 'https://icons.duckduckgo.com/ip3/'.$domain.'.ico'
            : 'https://www.google.com/s2/favicons?domain='.$domain.'&sz=64';

        $site = (new SiteCatalog())
            ->setDomain($domain)
            ->setTitle(self::DOMAIN_TITLES[$domain] ?? $domain)
            ->setFaviconUrl($favicon);
        $this->em->persist($site);

        return true;
    }
}
