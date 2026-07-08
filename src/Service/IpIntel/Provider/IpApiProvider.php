<?php

namespace App\Service\IpIntel\Provider;

use App\Service\IpIntel\IpIntelProviderInterface;
use App\Service\IpIntel\IpIntelResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class IpApiProvider implements IpIntelProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly bool $ipApiEnabled = true,
    ) {
    }

    public function analyze(string $ip): IpIntelResult
    {
        $result = new IpIntelResult($ip);

        if (!$this->ipApiEnabled) {
            return $result;
        }

        try {
            $response = $this->httpClient->request('GET', sprintf('https://ip-api.com/json/%s', rawurlencode($ip)), [
                'query' => [
                    'fields' => 'status,message,country,city,isp,org,as,hosting,proxy,mobile,query',
                ],
                'timeout' => 8,
            ]);
            $data = $response->toArray(false);
        } catch (Throwable) {
            return $result;
        }

        if (($data['status'] ?? null) !== 'success') {
            return $result;
        }

        $result->source = 'ip-api';
        $result->country = $this->normalizeString($data['country'] ?? null);
        $result->city = $this->normalizeString($data['city'] ?? null);
        $result->isp = $this->normalizeString($data['isp'] ?? null);
        $result->organization = $this->normalizeString($data['org'] ?? null) ?? $result->organization;
        $result->isHosting = isset($data['hosting']) ? (bool) $data['hosting'] : null;
        $result->isProxy = isset($data['proxy']) ? (bool) $data['proxy'] : null;
        $result->isMobile = isset($data['mobile']) ? (bool) $data['mobile'] : null;

        $asn = $this->parseAsn($data['as'] ?? null);
        if ($asn['asn'] !== null) {
            $result->asn = $asn['asn'];
        }
        if ($asn['organization'] !== null) {
            $result->organization ??= $asn['organization'];
        }

        return $result;
    }

    /**
     * @return array{asn: ?int, organization: ?string}
     */
    private function parseAsn(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return ['asn' => null, 'organization' => null];
        }

        if (preg_match('/^AS(\d+)\s+(.*)$/i', trim($value), $matches)) {
            return [
                'asn' => (int) $matches[1],
                'organization' => $this->normalizeString($matches[2]),
            ];
        }

        return ['asn' => null, 'organization' => $this->normalizeString($value)];
    }

    private function normalizeString(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
