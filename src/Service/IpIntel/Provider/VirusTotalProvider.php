<?php

namespace App\Service\IpIntel\Provider;

use App\Service\IpIntel\IpIntelProviderInterface;
use App\Service\IpIntel\IpIntelResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class VirusTotalProvider implements IpIntelProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $virusTotalApiKey = '',
    ) {
    }

    public function analyze(string $ip): IpIntelResult
    {
        $result = new IpIntelResult($ip);

        if (trim($this->virusTotalApiKey) === '') {
            return $result;
        }

        try {
            $response = $this->httpClient->request('GET', sprintf('https://www.virustotal.com/api/v3/ip_addresses/%s', rawurlencode($ip)), [
                'headers' => [
                    'x-apikey' => trim($this->virusTotalApiKey),
                    'Accept' => 'application/json',
                ],
                'timeout' => 8,
            ]);
            $data = $response->toArray(false);
        } catch (Throwable) {
            return $result;
        }

        $result->source = 'virustotal';
        $stats = $data['data']['attributes']['last_analysis_stats'] ?? [];
        if (is_array($stats)) {
            $result->malicious = isset($stats['malicious']) ? (int) $stats['malicious'] : null;
            $result->suspicious = isset($stats['suspicious']) ? (int) $stats['suspicious'] : null;
        }

        return $result;
    }
}
