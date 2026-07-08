<?php

namespace App\Service\IpIntel;

final class IpIntelResult
{
    public function __construct(
        public string $ip,
        public ?int $asn = null,
        public ?string $organization = null,
        public ?string $isp = null,
        public ?string $country = null,
        public ?string $city = null,
        public ?string $reverseDns = null,
        public ?bool $isHosting = null,
        public ?bool $isProxy = null,
        public ?bool $isMobile = null,
        public ?int $abuseScore = null,
        public ?int $malicious = null,
        public ?int $suspicious = null,
        public ?string $category = null,
        public ?int $confidence = null,
        public ?string $source = null,
        public ?\DateTimeImmutable $checkedAt = null,
    ) {
    }

    public function merge(self $other): void
    {
        $this->asn ??= $other->asn;
        $this->organization ??= $other->organization;
        $this->isp ??= $other->isp;
        $this->country ??= $other->country;
        $this->city ??= $other->city;
        $this->reverseDns ??= $other->reverseDns;
        $this->abuseScore = $this->mergeNumeric($this->abuseScore, $other->abuseScore);
        $this->malicious = $this->mergeNumeric($this->malicious, $other->malicious);
        $this->suspicious = $this->mergeNumeric($this->suspicious, $other->suspicious);
        $this->category ??= $other->category;
        $this->confidence = $this->mergeNumeric($this->confidence, $other->confidence);

        if ($other->isHosting === true || $this->isHosting === null) {
            $this->isHosting = $other->isHosting ?? $this->isHosting;
        }
        if ($other->isProxy === true || $this->isProxy === null) {
            $this->isProxy = $other->isProxy ?? $this->isProxy;
        }
        if ($other->isMobile === true || $this->isMobile === null) {
            $this->isMobile = $other->isMobile ?? $this->isMobile;
        }

        $this->source = $this->mergeSource($this->source, $other->source);
        $this->checkedAt ??= $other->checkedAt;
    }

    public function isEmpty(): bool
    {
        return $this->asn === null
            && $this->organization === null
            && $this->isp === null
            && $this->country === null
            && $this->city === null
            && $this->reverseDns === null
            && $this->isHosting === null
            && $this->isProxy === null
            && $this->isMobile === null
            && $this->abuseScore === null
            && $this->malicious === null
            && $this->suspicious === null
            && $this->category === null
            && $this->confidence === null
            && $this->source === null;
    }

    private function mergeNumeric(?int $current, ?int $incoming): ?int
    {
        if ($incoming === null) {
            return $current;
        }

        if ($current === null) {
            return $incoming;
        }

        return max($current, $incoming);
    }

    private function mergeSource(?string $current, ?string $incoming): ?string
    {
        $sources = [];
        foreach ([$current, $incoming] as $sourceList) {
            if (!is_string($sourceList) || trim($sourceList) === '') {
                continue;
            }

            foreach (preg_split('/\s*,\s*/', $sourceList) ?: [] as $source) {
                $source = trim($source);
                if ($source !== '') {
                    $sources[$source] = $source;
                }
            }
        }

        return $sources === [] ? null : implode(',', array_values($sources));
    }
}
