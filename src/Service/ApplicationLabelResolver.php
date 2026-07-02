<?php

namespace App\Service;

use App\Entity\SiteCatalog;
use Doctrine\ORM\EntityManagerInterface;

class ApplicationLabelResolver
{
    /** @var array<int, string> */
    private const PORT_LABELS = [
        22 => 'SSH',
        25 => 'SMTP',
        53 => 'DNS',
        80 => 'HTTP',
        110 => 'POP3',
        123 => 'NTP',
        143 => 'IMAP',
        443 => 'HTTPS',
        465 => 'SMTP',
        587 => 'SMTP',
        993 => 'IMAP',
        995 => 'POP3',
    ];

    /** @var array<string, string> */
    private const DOMAIN_LABELS = [
        'google.com' => 'Google',
        'youtube.com' => 'YouTube',
        'office.com' => 'Microsoft 365',
        'github.com' => 'GitHub',
        'wikipedia.org' => 'Wikipedia',
        'zoom.us' => 'Zoom',
        'cloudflare.com' => 'Cloudflare',
    ];

    /** @var array<string, string|null> */
    private array $reverseDnsCache = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function resolveFromFlow(
        ?int $protocol,
        ?int $remotePort,
        ?string $remoteIp = null,
        ?string $domain = null,
        ?string $applicationId = null,
    ): string {
        if ($domain) {
            $label = $this->labelForDomain($domain);
            if ($label !== null) {
                return $label;
            }
        }

        if ($applicationId !== null && !$this->isNumericIdentifier($applicationId) && !str_contains($applicationId, '.') && !str_contains($applicationId, '/')) {
            return $applicationId;
        }

        if ($remoteIp !== null) {
            $resolvedDomain = $this->domainFromHostname($this->reverseDns($remoteIp));
            if ($resolvedDomain !== null) {
                $label = $this->labelForDomain($resolvedDomain);
                if ($label !== null) {
                    return $label;
                }
            }
        }

        if ($remotePort !== null && isset(self::PORT_LABELS[$remotePort])) {
            return self::PORT_LABELS[$remotePort];
        }

        return 'Unknown';
    }

    public function labelForItem(array $item): string
    {
        $domain = is_string($item['domain'] ?? null) ? $item['domain'] : null;
        $remotePort = isset($item['dstPort']) ? (int) $item['dstPort'] : null;
        $remoteIp = isset($item['remoteIp']) ? (string) $item['remoteIp'] : null;
        $protocol = isset($item['protocol']) ? (int) $item['protocol'] : null;
        $label = $item['name'] ?? $item['application'] ?? $item['applicationId'] ?? null;

        if ($domain !== null) {
            $domainLabel = $this->labelForDomain($domain);
            if ($domainLabel !== null) {
                return $domainLabel;
            }
        }

        if (is_string($label)) {
            $knownLabel = $this->labelForDomain($label);
            if ($knownLabel !== null) {
                return $knownLabel;
            }

            if (preg_match('/^(?<proto>[A-Za-z0-9]+)\/(?<port>\d+)$/', $label, $matches) === 1) {
                $protocol = ctype_digit($matches['proto']) ? (int) $matches['proto'] : $protocol;
                $remotePort = (int) $matches['port'];
                $label = null;
            }
        }

        if (is_string($label) && !$this->isNumericIdentifier($label) && !str_contains($label, '.') && !str_contains($label, '/')) {
            return $label;
        }

        return $this->resolveFromFlow($protocol, $remotePort, $remoteIp, $domain, is_string($label) ? $label : null);
    }

    public function domainForIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        return $this->domainFromHostname($this->reverseDns($ip));
    }

    private function labelForDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain, ". \t\n\r\0\x0B"));
        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_IP)) {
            return null;
        }

        $segments = explode('.', $domain);
        for ($offset = 0; $offset < count($segments) - 1; ++$offset) {
            $candidate = implode('.', array_slice($segments, $offset));
            if ($candidate === '') {
                continue;
            }

            if (isset(self::DOMAIN_LABELS[$candidate])) {
                return self::DOMAIN_LABELS[$candidate];
            }

            $site = $this->em->getRepository(SiteCatalog::class)->findOneBy(['domain' => $candidate]);
            if ($site && $this->isMeaningfulSiteTitle($site->getTitle(), $candidate)) {
                return $site->getTitle();
            }
        }

        return null;
    }

    private function isMeaningfulSiteTitle(?string $title, string $domain): bool
    {
        if ($title === null) {
            return false;
        }

        $title = trim($title);
        if ($title === '' || $this->isNumericIdentifier($title)) {
            return false;
        }

        return strtolower($title) !== strtolower($domain);
    }

    private function isNumericIdentifier(string $value): bool
    {
        return $value !== '' && ctype_digit($value);
    }

    private function reverseDns(string $ip): ?string
    {
        if (!array_key_exists($ip, $this->reverseDnsCache)) {
            $resolved = @gethostbyaddr($ip);
            $this->reverseDnsCache[$ip] = $resolved === false || $resolved === $ip ? null : strtolower(rtrim($resolved, '.'));
        }

        return $this->reverseDnsCache[$ip];
    }

    private function domainFromHostname(?string $hostname): ?string
    {
        if (!$hostname) {
            return null;
        }

        $hostname = strtolower(trim($hostname, ". \t\n\r\0\x0B"));
        if ($hostname === '' || filter_var($hostname, FILTER_VALIDATE_IP)) {
            return null;
        }

        $parts = explode('.', $hostname);
        if (count($parts) < 2) {
            return null;
        }

        return implode('.', array_slice($parts, -2));
    }
}
