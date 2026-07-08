<?php

namespace App\Service\IpIntel\Provider;

use App\Service\IpIntel\IpIntelProviderInterface;
use App\Service\IpIntel\IpIntelResult;
use GeoIp2\Database\Reader;
use Throwable;

class MaxMindProvider implements IpIntelProviderInterface
{
    private ?Reader $reader = null;

    public function __construct(private readonly string $maxmindDbPath = '')
    {
    }

    public function analyze(string $ip): IpIntelResult
    {
        $result = new IpIntelResult($ip);

        if ($this->maxmindDbPath === '' || !is_file($this->maxmindDbPath) || !class_exists(Reader::class)) {
            return $result;
        }

        try {
            $reader = $this->reader ??= new Reader($this->maxmindDbPath);

            try {
                $asnRecord = $reader->asn($ip);
                $result->asn = $asnRecord->autonomousSystemNumber ?: null;
                $result->organization = $this->normalizeString($asnRecord->autonomousSystemOrganization ?? null);
            } catch (Throwable) {
                // Ignore ASN lookup failures and continue with other providers.
            }

            try {
                $countryRecord = $reader->country($ip);
                $result->country = $this->normalizeString($countryRecord->country->name ?? $countryRecord->country->isoCode ?? null);
            } catch (Throwable) {
                // Not every GeoLite2 database supports country lookups.
            }
        } catch (Throwable) {
            return $result;
        }

        $result->source = 'maxmind';

        return $result;
    }

    private function normalizeString(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
