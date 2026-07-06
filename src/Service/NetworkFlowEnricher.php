<?php

namespace App\Service;

use App\Entity\DnsCacheRecord;
use Doctrine\ORM\EntityManagerInterface;

class NetworkFlowEnricher
{
    /** @var array<string, DnsCacheRecord|null> */
    private array $dnsCacheByIp = [];

    /** @var list<array{network: int, mask: int}> */
    private array $localNetworks;

    /** @var array<string, true> */
    private array $ownWanIps;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppClassifier $appClassifier,
        string $localSubnets = '',
        string $ownWanIps = '',
    ) {
        $this->localNetworks = $this->parseLocalSubnets($localSubnets);
        $this->ownWanIps = array_fill_keys(array_filter(array_map('trim', explode(',', $ownWanIps))), true);
    }

    /**
     * @return array{domain: ?string, app_name: string, organization: ?string, domain_source: ?string}
     */
    public function enrich(
        string $direction,
        ?string $srcIp,
        ?string $dstIp,
        ?int $protocol,
        ?int $srcPort,
        ?int $dstPort,
        ?string $currentDomain = null,
        ?string $currentDomainSource = null,
    ): array {
        $domain = null;
        $domainSource = null;

        foreach ($this->remoteIpCandidates($direction, $srcIp, $dstIp) as $externalIp) {
            $record = $this->resolveDnsCacheRecord($externalIp);
            if ($record !== null) {
                $domain = $record->getDomain();
                $domainSource = 'dns_cache';
                break;
            }
        }

        if ($domain === null) {
            $domain = $this->normalizeDomain($currentDomain);
            $domainSource = $domain !== null ? $this->normalizeDomainSource($currentDomainSource) : null;
        }

        return [
            'domain' => $domain,
            'app_name' => $this->appClassifier->classify($domain, $protocol, $srcPort, $dstPort),
            'organization' => $this->appClassifier->organizationForDomain($domain),
            'domain_source' => $domainSource,
        ];
    }

    public function resolveDnsCacheRecord(?string $externalIp): ?DnsCacheRecord
    {
        if ($externalIp === null || $externalIp === '') {
            return null;
        }

        if (array_key_exists($externalIp, $this->dnsCacheByIp)) {
            return $this->dnsCacheByIp[$externalIp];
        }

        $record = $this->em->getRepository(DnsCacheRecord::class)->createQueryBuilder('r')
            ->andWhere('r.resolvedIp = :ip')
            ->setParameter('ip', $externalIp)
            ->orderBy('r.lastSeenAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($record instanceof DnsCacheRecord) {
            $preferredRecord = $this->preferredDnsCacheRecord($record);
            $this->dnsCacheByIp[$externalIp] = $preferredRecord;

            return $preferredRecord;
        }

        $this->dnsCacheByIp[$externalIp] = null;

        return null;
    }

    private function preferredDnsCacheRecord(DnsCacheRecord $record): DnsCacheRecord
    {
        $alias = $this->em->getRepository(DnsCacheRecord::class)->findOneBy(
            ['cname' => $record->getDomain(), 'recordType' => 'CNAME'],
            ['lastSeenAt' => 'DESC', 'id' => 'DESC']
        );

        return $alias instanceof DnsCacheRecord ? $alias : $record;
    }

    /**
     * @return list<string>
     */
    public function remoteIpCandidates(string $direction, ?string $srcIp, ?string $dstIp): array
    {
        $ordered = match ($direction) {
            'upload' => [$dstIp, $srcIp],
            'download' => [$srcIp, $dstIp],
            default => [$dstIp, $srcIp],
        };

        $candidates = [];
        foreach ($ordered as $ip) {
            if (!is_string($ip) || $ip === '' || isset($candidates[$ip]) || $this->isIgnoredIp($ip)) {
                continue;
            }

            $candidates[$ip] = $ip;
        }

        return array_values($candidates);
    }

    public function isIgnoredIp(string $ip): bool
    {
        return isset($this->ownWanIps[$ip]) || $this->isLocalIp($ip);
    }

    /**
     * @return list<array{network: int, mask: int}>
     */
    private function parseLocalSubnets(string $localSubnets): array
    {
        $networks = [];

        foreach (array_filter(array_map('trim', explode(',', $localSubnets))) as $cidr) {
            [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, '32');
            $networkLong = ip2long($network);

            if ($networkLong === false || !is_numeric($prefix)) {
                continue;
            }

            $prefixLength = max(0, min(32, (int) $prefix));
            $mask = $prefixLength === 0 ? 0 : ((-1 << (32 - $prefixLength)) & 0xFFFFFFFF);
            $networks[] = [
                'network' => ip2long(long2ip($networkLong & $mask)),
                'mask' => $mask,
            ];
        }

        return $networks;
    }

    private function isLocalIp(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach ($this->localNetworks as $network) {
            if (($ipLong & $network['mask']) === $network['network']) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDomain(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '.');

        return $domain === '' || filter_var($domain, FILTER_VALIDATE_IP) ? null : $domain;
    }

    private function normalizeDomainSource(?string $domainSource): ?string
    {
        if ($domainSource === null) {
            return null;
        }

        $domainSource = trim($domainSource);

        return $domainSource === '' ? null : $domainSource;
    }
}
