<?php

namespace App\Service;

use App\Entity\IpIntel;
use App\Repository\IpIntelRepository;
use App\Service\IpIntel\IpIntelProviderInterface;
use App\Service\IpIntel\IpIntelResult;
use App\Service\IpIntel\Provider\AbuseIpDbProvider;
use App\Service\IpIntel\Provider\IpApiProvider;
use App\Service\IpIntel\Provider\MaxMindProvider;
use App\Service\IpIntel\Provider\VirusTotalProvider;
use Doctrine\DBAL\Connection;

class IpIntelService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IpIntelRepository $ipIntelRepository,
        private readonly MaxMindProvider $maxMindProvider,
        private readonly IpApiProvider $ipApiProvider,
        private readonly AbuseIpDbProvider $abuseIpDbProvider,
        private readonly VirusTotalProvider $virusTotalProvider,
        private readonly IpIntelCategoryClassifier $categoryClassifier,
        private readonly NetworkFlowContextResolver $flowContextResolver,
        private readonly int $ipIntelCacheDays = 30,
    ) {
    }

    public function analyze(string $ip): IpIntelResult
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return new IpIntelResult('');
        }

        $existing = $this->ipIntelRepository->findOneBy(['ip' => $ip]);
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', max(1, $this->ipIntelCacheDays)));

        if ($existing instanceof IpIntel && $existing->getCheckedAt() !== null && $existing->getCheckedAt() >= $cutoff) {
            return $this->toResult($existing);
        }

        $result = new IpIntelResult($ip);

        foreach ($this->cheapProviders() as $provider) {
            $partial = $provider->analyze($ip);
            $result->merge($partial);
        }

        $reverseDns = $this->resolveReverseDns($ip);
        if ($reverseDns !== null) {
            $result->reverseDns ??= $reverseDns;
            $result->source = $this->appendSource($result->source, 'reverse-dns');
        }

        $flowContext = $this->flowContextResolver->resolveForIp($ip);
        $result->category = $this->categoryClassifier->classify($result, $flowContext);

        if ($this->shouldQueryExpensiveProviders($result, $flowContext)) {
            foreach ($this->expensiveProviders() as $provider) {
                $partial = $provider->analyze($ip);
                $result->merge($partial);
            }

            $result->category = $this->categoryClassifier->classify($result, $flowContext);
        }

        $result->confidence = $this->confidenceForResult($result, $flowContext);
        $result->checkedAt = new \DateTimeImmutable();

        $this->persistIntelResult($result);

        return $result;
    }

    /**
     * @param list<string> $ips
     *
     * @return array<string, IpIntelResult>
     */
    public function analyzeMany(array $ips): array
    {
        $results = [];
        foreach (array_values(array_unique(array_filter(array_map('trim', $ips)))) as $ip) {
            $results[$ip] = $this->analyze($ip);
        }

        return $results;
    }

    public function storeDerivedClassification(string $ip, string $category, int $confidence, string $source, bool $override = false): IpIntelResult
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return new IpIntelResult('');
        }

        $existing = $this->ipIntelRepository->findOneBy(['ip' => $ip]);
        if ($existing instanceof IpIntel && !$override && $existing->getCategory() !== null && strtolower($existing->getCategory()) !== 'unknown') {
            return $this->toResult($existing);
        }

        $result = $existing instanceof IpIntel ? $this->toResult($existing) : new IpIntelResult($ip);
        $result->category = $category;
        $result->confidence = $existing instanceof IpIntel && $existing->getConfidence() !== null
            ? max($existing->getConfidence(), $confidence)
            : $confidence;
        $result->source = $this->appendSource($existing instanceof IpIntel ? $existing->getSource() : null, $source);
        $result->checkedAt = new \DateTimeImmutable();

        $this->persistIntelResult($result);

        return $result;
    }

    /**
     * @return list<IpIntelProviderInterface>
     */
    private function cheapProviders(): array
    {
        return [
            $this->maxMindProvider,
            $this->ipApiProvider,
        ];
    }

    /**
     * @return list<IpIntelProviderInterface>
     */
    private function expensiveProviders(): array
    {
        return [
            $this->abuseIpDbProvider,
            $this->virusTotalProvider,
        ];
    }

    private function shouldQueryExpensiveProviders(IpIntelResult $result, ?array $flowContext): bool
    {
        if ($result->category !== null && strtolower($result->category) !== 'unknown') {
            return false;
        }

        if ($result->isHosting === true || $result->isProxy === true || $result->isMobile === true) {
            return false;
        }

        $signals = implode(' ', array_filter([
            $result->organization,
            $result->isp,
            $result->country,
            $result->city,
            $result->reverseDns,
            $flowContext['domain'] ?? null,
            $flowContext['organization'] ?? null,
            $flowContext['app_name'] ?? null,
        ]));

        if ($signals !== '' && $this->containsAny($signals, [
            'cloudflare',
            'akamai',
            'fastly',
            'cdn',
            'google',
            'microsoft',
            'apple',
            'amazon',
            'm247',
            'ovh',
            'hetzner',
            'leaseweb',
            'digitalocean',
            'linode',
            'vultr',
            'datacamp',
        ])) {
            return false;
        }

        return true;
    }

    private function resolveReverseDns(string $ip): ?string
    {
        $host = @gethostbyaddr($ip);
        if (!is_string($host)) {
            return null;
        }

        $host = trim(strtolower($host));
        if ($host === '' || $host === $ip) {
            return null;
        }

        return rtrim($host, '.');
    }

    /**
     * @param array<string, mixed>|null $flowContext
     */
    private function confidenceForResult(IpIntelResult $result, ?array $flowContext): int
    {
        $confidence = 40;

        if ($result->asn !== null) {
            $confidence += 10;
        }
        if ($result->organization !== null) {
            $confidence += 10;
        }
        if ($result->country !== null) {
            $confidence += 5;
        }
        if ($result->reverseDns !== null) {
            $confidence += 10;
        }
        if ($result->isHosting === true || $result->isProxy === true) {
            $confidence += 10;
        }
        if ($result->abuseScore !== null) {
            $confidence = max($confidence, $result->abuseScore);
        }
        if ($result->malicious !== null && $result->malicious > 0) {
            $confidence = max($confidence, 80 + min(20, $result->malicious * 5));
        }
        if ($result->suspicious !== null && $result->suspicious > 0) {
            $confidence = max($confidence, 60 + min(20, $result->suspicious * 5));
        }
        if ($flowContext !== null) {
            $confidence += 5;
        }

        return max(0, min(100, $confidence));
    }

    private function persistIntelResult(IpIntelResult $result): void
    {
        $now = new \DateTimeImmutable();

        $this->connection->executeStatement(
            'INSERT INTO ip_intel (
                ip, asn, organization, isp, country, city, reverse_dns,
                is_hosting, is_proxy, is_mobile, abuse_score, category, confidence, source,
                checked_at, created_at, updated_at
             ) VALUES (
                :ip, :asn, :organization, :isp, :country, :city, :reverseDns,
                :isHosting, :isProxy, :isMobile, :abuseScore, :category, :confidence, :source,
                :checkedAt, :createdAt, :updatedAt
             )
             ON DUPLICATE KEY UPDATE
                asn = VALUES(asn),
                organization = VALUES(organization),
                isp = VALUES(isp),
                country = VALUES(country),
                city = VALUES(city),
                reverse_dns = VALUES(reverse_dns),
                is_hosting = VALUES(is_hosting),
                is_proxy = VALUES(is_proxy),
                is_mobile = VALUES(is_mobile),
                abuse_score = VALUES(abuse_score),
                category = VALUES(category),
                confidence = VALUES(confidence),
                source = VALUES(source),
                checked_at = VALUES(checked_at),
                updated_at = VALUES(updated_at)',
            [
                'ip' => $result->ip,
                'asn' => $result->asn,
                'organization' => $result->organization,
                'isp' => $result->isp,
                'country' => $result->country,
                'city' => $result->city,
                'reverseDns' => $result->reverseDns,
                'isHosting' => $result->isHosting === null ? null : (int) $result->isHosting,
                'isProxy' => $result->isProxy === null ? null : (int) $result->isProxy,
                'isMobile' => $result->isMobile === null ? null : (int) $result->isMobile,
                'abuseScore' => $result->abuseScore,
                'category' => $result->category,
                'confidence' => $result->confidence,
                'source' => $result->source,
                'checkedAt' => $result->checkedAt?->format('Y-m-d H:i:s'),
                'createdAt' => $now->format('Y-m-d H:i:s'),
                'updatedAt' => $now->format('Y-m-d H:i:s'),
            ]
        );
    }

    private function toResult(IpIntel $entity): IpIntelResult
    {
        return new IpIntelResult(
            ip: $entity->getIp(),
            asn: $entity->getAsn(),
            organization: $entity->getOrganization(),
            isp: $entity->getIsp(),
            country: $entity->getCountry(),
            city: $entity->getCity(),
            reverseDns: $entity->getReverseDns(),
            isHosting: $entity->getIsHosting(),
            isProxy: $entity->getIsProxy(),
            isMobile: $entity->getIsMobile(),
            abuseScore: $entity->getAbuseScore(),
            category: $entity->getCategory(),
            confidence: $entity->getConfidence(),
            source: $entity->getSource(),
            checkedAt: $entity->getCheckedAt(),
        );
    }

    private function appendSource(?string $current, string $source): string
    {
        $sources = [];
        foreach ([$current, $source] as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            foreach (preg_split('/\s*,\s*/', $value) ?: [] as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $sources[$token] = $token;
                }
            }
        }

        return implode(',', array_values($sources));
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        $haystack = strtolower($haystack);

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
