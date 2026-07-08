<?php

namespace App\Service;

use App\Service\IpIntel\IpIntelResult;

class IpIntelCategoryClassifier
{
    private const GOOGLE_PATTERNS = [
        'google',
        '1e100.net',
        'googleusercontent',
        'gvt1',
        'youtube',
        'googlesyndication',
        'googleapis',
        'googlevideo',
    ];

    private const META_PATTERNS = [
        'meta',
        'facebook',
        'instagram',
        'whatsapp',
        'fbcdn',
        'cdninstagram',
    ];

    private const CLOUDFLARE_PATTERNS = [
        'cloudflare',
        'cloudflareusercontent',
    ];

    private const MICROSOFT_PATTERNS = [
        'microsoft',
        'windows',
        'msedge',
        'azure',
        'office',
        'live.com',
        'bing',
    ];

    private const APPLE_PATTERNS = [
        'apple',
        'icloud',
        'aaplimg',
        'itunes',
        'me.com',
    ];

    private const AMAZON_PATTERNS = [
        'amazon',
        'aws',
        'cloudfront',
        'amazonaws',
    ];

    private const CDN_PATTERNS = [
        'fastly',
        'akamai',
        'cdn',
        'edgecast',
        'cdn77',
        'bunny',
        'jsdelivr',
        'cachefly',
        'imperva',
        'incapsula',
        'keycdn',
    ];

    private const HOSTING_PATTERNS = [
        'hosting',
        'hoster',
        'datacenter',
        'data center',
        'vps',
        'dedicated',
        'server',
        'm247',
        'ovh',
        'hetzner',
        'leaseweb',
        'digitalocean',
        'linode',
        'vultr',
        'datacamp',
        'contabo',
    ];

    private const VPN_PATTERNS = [
        'vpn',
        'proxy',
        'tunnel',
        'private internet access',
        'mullvad',
        'nord',
        'surfshark',
        'expressvpn',
        'proton',
        'windscribe',
        'torguard',
        'ivpn',
        'tunnelbear',
        'private relay',
        'tor',
    ];

    private const TORRENT_PATTERNS = [
        'torrent',
        'bittorrent',
        'qbittorrent',
        'utorrent',
        'transmission',
        'deluge',
    ];

    private const GAMING_PATTERNS = [
        'steam',
        'epicgames',
        'battle.net',
        'blizzard',
        'riot',
        'xbox',
        'playstation',
        'nintendo',
    ];

    private const REMOTE_ACCESS_PATTERNS = [
        'teamviewer',
        'anydesk',
        'logmein',
        'splashtop',
        'remote',
        'rdp',
        'vnc',
    ];

    private const SOCIAL_PATTERNS = [
        'twitter',
        'x.com',
        'facebook',
        'instagram',
        'linkedin',
        'snapchat',
        'reddit',
        'threads',
    ];

    private const VIDEO_PATTERNS = [
        'youtube',
        'netflix',
        'tiktok',
        'vimeo',
        'twitch',
        'primevideo',
        'disney',
    ];

    private const MESSENGER_PATTERNS = [
        'whatsapp',
        'telegram',
        'viber',
        'messenger',
        'discord',
        'slack',
        'signal',
        'wechat',
    ];

    public function classify(IpIntelResult $result, ?array $flowContext = null): string
    {
        $values = $this->normalisedValues($result, $flowContext);

        if ($this->matchesAny($values, self::GOOGLE_PATTERNS)) {
            return 'google';
        }

        if ($this->matchesAny($values, self::META_PATTERNS)) {
            return 'meta';
        }

        if ($this->matchesAny($values, self::CLOUDFLARE_PATTERNS)) {
            return 'cloudflare';
        }

        if ($this->matchesAny($values, self::MICROSOFT_PATTERNS)) {
            return 'microsoft';
        }

        if ($this->matchesAny($values, self::APPLE_PATTERNS)) {
            return 'apple';
        }

        if ($this->matchesAny($values, self::AMAZON_PATTERNS)) {
            return 'amazon';
        }

        if ($this->matchesAny($values, self::CDN_PATTERNS)) {
            return 'cdn';
        }

        if ($this->matchesAny($values, self::VPN_PATTERNS) || $result->isProxy === true) {
            return 'vpn';
        }

        if ($this->matchesAny($values, self::HOSTING_PATTERNS) || $result->isHosting === true) {
            return 'hosting';
        }

        if ($this->matchesAny($values, self::TORRENT_PATTERNS) || $this->looksLikeTorrent($flowContext)) {
            return 'torrent';
        }

        if ($this->matchesAny($values, self::GAMING_PATTERNS)) {
            return 'gaming';
        }

        if ($this->matchesAny($values, self::REMOTE_ACCESS_PATTERNS) || $this->looksLikeRemoteAccess($flowContext)) {
            return 'remote_access';
        }

        if ($this->matchesAny($values, self::SOCIAL_PATTERNS)) {
            return 'social';
        }

        if ($this->matchesAny($values, self::VIDEO_PATTERNS)) {
            return 'video';
        }

        if ($this->matchesAny($values, self::MESSENGER_PATTERNS)) {
            return 'messenger';
        }

        return 'unknown';
    }

    /**
     * @return list<string>
     */
    private function normalisedValues(IpIntelResult $result, ?array $flowContext): array
    {
        $values = array_filter([
            $result->organization,
            $result->isp,
            $result->country,
            $result->city,
            $result->reverseDns,
            $flowContext['domain'] ?? null,
            $flowContext['organization'] ?? null,
            $flowContext['app_name'] ?? null,
        ]);

        return array_values(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            $values
        ));
    }

    /**
     * @param list<string> $values
     * @param list<string> $patterns
     */
    private function matchesAny(array $values, array $patterns): bool
    {
        foreach ($values as $value) {
            foreach ($patterns as $pattern) {
                if (str_contains($value, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $flowContext
     */
    private function looksLikeTorrent(?array $flowContext): bool
    {
        if ($flowContext === null) {
            return false;
        }

        $protocol = isset($flowContext['protocol']) ? (int) $flowContext['protocol'] : null;
        $srcPort = isset($flowContext['src_port']) ? (int) $flowContext['src_port'] : null;
        $dstPort = isset($flowContext['dst_port']) ? (int) $flowContext['dst_port'] : null;

        if ($protocol === 17 && $dstPort !== null && $dstPort >= 6881 && $dstPort <= 6999) {
            return true;
        }

        return $srcPort !== null && $srcPort >= 6881 && $srcPort <= 6999;
    }

    /**
     * @param array<string, mixed>|null $flowContext
     */
    private function looksLikeRemoteAccess(?array $flowContext): bool
    {
        if ($flowContext === null) {
            return false;
        }

        $srcPort = isset($flowContext['src_port']) ? (int) $flowContext['src_port'] : null;
        $dstPort = isset($flowContext['dst_port']) ? (int) $flowContext['dst_port'] : null;
        $ports = array_filter([$srcPort, $dstPort], static fn ($value): bool => is_int($value));

        foreach ($ports as $port) {
            if (in_array($port, [22, 3389, 5900, 5938, 5939], true)) {
                return true;
            }
        }

        return false;
    }
}
