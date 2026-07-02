<?php

namespace App\Service;

use App\Entity\DnsCacheRecord;
use Doctrine\ORM\EntityManagerInterface;

class NetworkFlowEnricher
{
    /** @var array<string, DnsCacheRecord|null> */
    private array $dnsCacheByIp = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppClassifier $appClassifier,
    ) {
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
        $externalIp = $this->externalIp($direction, $srcIp, $dstIp);
        $domain = null;
        $domainSource = null;

        if ($externalIp !== null) {
            $record = $this->resolveDnsCacheRecord($externalIp);
            if ($record !== null) {
                $domain = $record->getDomain();
                $domainSource = 'dns_cache';
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
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($record instanceof DnsCacheRecord) {
            $this->dnsCacheByIp[$externalIp] = $record;
            return $record;
        }

        return null;
    }

    private function externalIp(string $direction, ?string $srcIp, ?string $dstIp): ?string
    {
        return match ($direction) {
            'upload' => $dstIp,
            'download' => $srcIp,
            'local' => null,
            default => null,
        };
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
