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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

class IpIntelService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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

        foreach ($this->providers() as $provider) {
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
        $result->confidence = $this->confidenceForResult($result, $flowContext);
        $result->checkedAt = new \DateTimeImmutable();

        $entity = $existing instanceof IpIntel ? $existing : (new IpIntel())->setIp($ip);
        $this->persistIntelEntity($entity, $result);

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

        $entity = $this->ipIntelRepository->findOneBy(['ip' => $ip]);
        if (!$entity instanceof IpIntel) {
            $entity = (new IpIntel())->setIp($ip);
        }

        if (!$override && $entity->getCategory() !== null && strtolower($entity->getCategory()) !== 'unknown') {
            return $this->toResult($entity);
        }

        $currentConfidence = $entity->getConfidence();
        $entity
            ->setCategory($category)
            ->setSource($this->appendSource($entity->getSource(), $source))
            ->setCheckedAt(new \DateTimeImmutable());

        if ($currentConfidence === null || $confidence > $currentConfidence) {
            $entity->setConfidence($confidence);
        }

        $this->persistIntelEntity($entity, $this->toResult($entity));

        return $this->toResult($entity);
    }

    /**
     * @return list<IpIntelProviderInterface>
     */
    private function providers(): array
    {
        return [
            $this->maxMindProvider,
            $this->ipApiProvider,
            $this->abuseIpDbProvider,
            $this->virusTotalProvider,
        ];
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

    private function applyResultToEntity(IpIntel $entity, IpIntelResult $result): void
    {
        $entity
            ->setAsn($result->asn)
            ->setOrganization($result->organization)
            ->setIsp($result->isp)
            ->setCountry($result->country)
            ->setCity($result->city)
            ->setReverseDns($result->reverseDns)
            ->setIsHosting($result->isHosting)
            ->setIsProxy($result->isProxy)
            ->setIsMobile($result->isMobile)
            ->setAbuseScore($result->abuseScore)
            ->setCategory($result->category)
            ->setConfidence($result->confidence)
            ->setSource($result->source)
            ->setCheckedAt($result->checkedAt);
    }

    private function persistIntelEntity(IpIntel $entity, IpIntelResult $result): void
    {
        $this->applyResultToEntity($entity, $result);

        try {
            $this->em->persist($entity);
            $this->em->flush();
            return;
        } catch (UniqueConstraintViolationException) {
            $this->em->clear();
        }

        $existing = $this->ipIntelRepository->findOneBy(['ip' => $result->ip]);
        if (!$existing instanceof IpIntel) {
            throw new \RuntimeException(sprintf('Failed to persist IP intel for %s after duplicate-key retry.', $result->ip));
        }

        $this->applyResultToEntity($existing, $result);
        $this->em->persist($existing);
        $this->em->flush();
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
}
