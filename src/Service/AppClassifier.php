<?php

namespace App\Service;

use App\Entity\SiteCatalog;
use Doctrine\ORM\EntityManagerInterface;

class AppClassifier
{
    /** @var array<int, string> */
    private const PORT_RULES = [
        53 => 'DNS',
        80 => 'HTTP',
    ];

    /** @var array<int, string> */
    private const PORT_443_RULES = [
        17 => 'QUIC/HTTPS',
        6 => 'HTTPS',
    ];

    /**
     * @var array<int, array{app: string, organization: ?string, fragments: list<string>}>
     */
    private const DOMAIN_RULES = [
        ['app' => 'YouTube', 'organization' => 'Google', 'fragments' => ['youtube.com', 'googlevideo.com', 'ytimg.com']],
        ['app' => 'Instagram', 'organization' => 'Meta', 'fragments' => ['instagram.com', 'cdninstagram.com', 'instagram']],
        ['app' => 'Facebook', 'organization' => 'Meta', 'fragments' => ['facebook.com', 'fbcdn.net', 'c10r.facebook.com']],
        ['app' => 'WhatsApp', 'organization' => 'Meta', 'fragments' => ['whatsapp.net', 'whatsapp.com']],
        ['app' => 'TikTok', 'organization' => 'ByteDance', 'fragments' => ['tiktok.com', 'tiktokcdn.com', 'bytewlb', 'pangle']],
        ['app' => 'Telegram', 'organization' => 'Telegram', 'fragments' => ['telegram.org', 't.me', 'telegram.me']],
        ['app' => 'Apple', 'organization' => 'Apple', 'fragments' => ['apple.com', 'icloud.com', 'aaplimg.com', 'itunes.apple.com']],
        ['app' => 'Microsoft', 'organization' => 'Microsoft', 'fragments' => ['microsoft.com', 'windowsupdate.com', 'live.com', 'office.com', 'msedge.net']],
        ['app' => 'GitHub', 'organization' => 'Microsoft', 'fragments' => ['github.com', 'githubusercontent.com']],
        ['app' => 'Wikipedia', 'organization' => 'Wikimedia', 'fragments' => ['wikipedia.org', 'wikimedia.org']],
        ['app' => 'Steam', 'organization' => 'Valve', 'fragments' => ['steamcontent.com', 'steampowered.com']],
        ['app' => 'Viber', 'organization' => 'Rakuten', 'fragments' => ['viber.com']],
        ['app' => 'Zoom', 'organization' => 'Zoom', 'fragments' => ['zoom.us']],
        ['app' => 'Google', 'organization' => 'Google', 'fragments' => ['google.com', 'gstatic.com', 'googleusercontent.com', 'googleapis.com', 'gvt1.com', '1e100.net', 'googlesyndication.com', 'googleadservices.com', 'ggpht.com']],
    ];

    /**
     * @var array<string, string>
     */
    private const ORGANIZATION_BY_APP = [
        'YouTube' => 'Google',
        'Google' => 'Google',
        'Instagram' => 'Meta',
        'Facebook' => 'Meta',
        'WhatsApp' => 'Meta',
        'TikTok' => 'ByteDance',
        'Telegram' => 'Telegram',
        'Apple' => 'Apple',
        'Microsoft' => 'Microsoft',
        'GitHub' => 'Microsoft',
        'Wikipedia' => 'Wikimedia',
        'Steam' => 'Valve',
        'Viber' => 'Rakuten',
        'Zoom' => 'Zoom',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function classify(?string $domain, ?int $protocol, ?int $srcPort, ?int $dstPort): string
    {
        $normalizedDomain = $this->normalizeDomain($domain);
        if ($normalizedDomain !== null) {
            $domainLabel = $this->labelFromDomainRules($normalizedDomain);
            if ($domainLabel !== null) {
                return $domainLabel;
            }

            $catalogLabel = $this->catalogLabelForDomain($normalizedDomain);
            if ($catalogLabel !== null) {
                return $catalogLabel;
            }
        }

        if ($this->hasPort($srcPort, 53) || $this->hasPort($dstPort, 53)) {
            return 'DNS';
        }

        if ($this->hasPort($srcPort, 80) || $this->hasPort($dstPort, 80)) {
            return 'HTTP';
        }

        if ($this->hasPort($srcPort, 443) || $this->hasPort($dstPort, 443)) {
            return $protocol === 17 ? 'QUIC/HTTPS' : 'HTTPS';
        }

        return 'Unknown';
    }

    public function organizationForDomain(?string $domain): ?string
    {
        $normalizedDomain = $this->normalizeDomain($domain);
        if ($normalizedDomain === null) {
            return null;
        }

        $label = $this->labelFromDomainRules($normalizedDomain);
        if ($label !== null) {
            return self::ORGANIZATION_BY_APP[$label] ?? null;
        }

        $catalogLabel = $this->catalogLabelForDomain($normalizedDomain);
        if ($catalogLabel !== null && isset(self::ORGANIZATION_BY_APP[$catalogLabel])) {
            return self::ORGANIZATION_BY_APP[$catalogLabel];
        }

        return null;
    }

    private function catalogLabelForDomain(string $domain): ?string
    {
        $parts = explode('.', $domain);
        for ($offset = 0; $offset < count($parts) - 1; ++$offset) {
            $candidate = implode('.', array_slice($parts, $offset));
            if ($candidate === '') {
                continue;
            }

            $site = $this->em->getRepository(SiteCatalog::class)->findOneBy(['domain' => $candidate]);
            $title = $site?->getTitle();
            if (is_string($title)) {
                $title = trim($title);
                if ($title !== '' && !ctype_digit($title) && strtolower($title) !== strtolower($candidate)) {
                    return $title;
                }
            }
        }

        return null;
    }

    private function labelFromDomainRules(string $domain): ?string
    {
        foreach (self::DOMAIN_RULES as $rule) {
            foreach ($rule['fragments'] as $fragment) {
                if (str_contains($domain, $fragment)) {
                    return $rule['app'];
                }
            }
        }

        return null;
    }

    private function hasPort(?int $port, int $expected): bool
    {
        return $port !== null && $port === $expected;
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
}
