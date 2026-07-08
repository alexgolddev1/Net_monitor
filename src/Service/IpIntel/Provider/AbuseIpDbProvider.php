<?php

namespace App\Service\IpIntel\Provider;

use App\Service\IpIntel\IpIntelProviderInterface;
use App\Service\IpIntel\IpIntelResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class AbuseIpDbProvider implements IpIntelProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $abuseIpDbApiKey = '',
    ) {
    }

    public function analyze(string $ip): IpIntelResult
    {
        $result = new IpIntelResult($ip);

        if (trim($this->abuseIpDbApiKey) === '') {
            return $result;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.abuseipdb.com/api/v2/check', [
                'query' => [
                    'ipAddress' => $ip,
                    'maxAgeInDays' => 90,
                    'verbose' => true,
                ],
                'headers' => [
                    'Key' => trim($this->abuseIpDbApiKey),
                    'Accept' => 'application/json',
                ],
                'timeout' => 8,
            ]);
            $data = $response->toArray(false);
        } catch (Throwable) {
            return $result;
        }

        $result->source = 'abuseipdb';
        $result->abuseScore = (int) ($data['data']['abuseConfidenceScore'] ?? 0);

        return $result;
    }
}
