<?php

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

class UnknownTrafficAnalyzer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IpIntelService $ipIntelService,
        private readonly int $ipIntelCacheDays = 30,
    ) {
    }

    public function analyze(): TrafficAnalysisSummary
    {
        $summary = new TrafficAnalysisSummary();
        $pendingIps = $this->discoverExternalIps();

        $intelResults = [];
        foreach ($pendingIps as $ip) {
            $intelResults[$ip] = $this->ipIntelService->analyze($ip);
        }
        $summary->refreshedIps = count($intelResults);

        foreach ($intelResults as $result) {
            $this->incrementSummaryByCategory($summary, $result->category);
        }

        $summary->torrent = $this->detectTorrent();
        $summary->vpn = $this->detectVpn();
        $this->detectCommonUnknownService();

        return $summary;
    }

    /**
     * @return list<string>
     */
    public function discoverExternalIps(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ip FROM (
                 SELECT src_ip AS ip FROM network_flow WHERE src_ip IS NOT NULL AND src_ip <> \'\'
                 UNION
                 SELECT dst_ip AS ip FROM network_flow WHERE dst_ip IS NOT NULL AND dst_ip <> \'\'
                 UNION
                 SELECT post_nat_src_ip AS ip FROM network_flow WHERE post_nat_src_ip IS NOT NULL AND post_nat_src_ip <> \'\'
                 UNION
                 SELECT post_nat_dst_ip AS ip FROM network_flow WHERE post_nat_dst_ip IS NOT NULL AND post_nat_dst_ip <> \'\'
             ) candidates'
        );

        if ($rows === []) {
            return [];
        }

        $ips = [];
        foreach ($rows as $row) {
            $ip = isset($row['ip']) ? trim((string) $row['ip']) : '';
            if ($ip === '') {
                continue;
            }

            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                continue;
            }

            $ips[$ip] = $ip;
        }

        $freshCutoff = new \DateTimeImmutable(sprintf('-%d days', max(1, $this->ipIntelCacheDays)));
        if ($ips === []) {
            return [];
        }

        $freshRows = $this->connection->fetchFirstColumn(
            'SELECT ip FROM ip_intel WHERE checked_at >= :cutoff AND ip IN (:ips)',
            [
                'cutoff' => $freshCutoff->format('Y-m-d H:i:s'),
                'ips' => array_values($ips),
            ],
            [
                'ips' => ArrayParameterType::STRING,
            ]
        );

        foreach ($freshRows as $ip) {
            unset($ips[(string) $ip]);
        }

        return array_values($ips);
    }

    public function detectTorrent(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT src_ip, src_port, COUNT(DISTINCT dst_ip) AS distinct_dst_ips, COUNT(DISTINCT dst_port) AS distinct_dst_ports
             FROM network_flow
             WHERE received_at >= :cutoff
               AND protocol = 17
               AND src_ip IS NOT NULL
               AND src_ip <> \'\'
               AND src_port IS NOT NULL
             GROUP BY src_ip, src_port
             HAVING COUNT(DISTINCT dst_ip) >= 50 AND COUNT(DISTINCT dst_port) >= 30',
            [
                'cutoff' => (new \DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s'),
            ]
        );

        $count = 0;
        foreach ($rows as $row) {
            $ip = isset($row['src_ip']) ? (string) $row['src_ip'] : '';
            if ($ip === '') {
                continue;
            }

            $this->ipIntelService->storeDerivedClassification($ip, 'torrent', 95, 'traffic:torrent', true);
            ++$count;
        }

        return $count;
    }

    public function detectVpn(): int
    {
        $cutoff = (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s');
        $totalRows = $this->connection->fetchAllAssociative(
            'SELECT client_id, SUM(COALESCE(bytes, 0)) AS total_bytes
             FROM network_flow
             WHERE received_at >= :cutoff
               AND client_id IS NOT NULL
             GROUP BY client_id',
            ['cutoff' => $cutoff]
        );

        $totalsByClient = [];
        foreach ($totalRows as $row) {
            $clientId = (int) $row['client_id'];
            $totalsByClient[$clientId] = max(1, (int) $row['total_bytes']);
        }

        if ($totalsByClient === []) {
            return 0;
        }

        $byClientRows = $this->connection->fetchAllAssociative(
            'SELECT client_id, dst_ip, SUM(COALESCE(bytes, 0)) AS bytes
             FROM network_flow
             WHERE received_at >= :cutoff
               AND client_id IS NOT NULL
               AND dst_ip IS NOT NULL
               AND dst_ip <> \'\'
             GROUP BY client_id, dst_ip',
            ['cutoff' => $cutoff]
        );

        $count = 0;
        foreach ($byClientRows as $row) {
            $clientId = (int) $row['client_id'];
            $dstIp = isset($row['dst_ip']) ? (string) $row['dst_ip'] : '';
            $bytes = (int) $row['bytes'];
            $totalBytes = $totalsByClient[$clientId] ?? 0;

            if ($dstIp === '' || $totalBytes <= 0 || ($bytes / $totalBytes) <= 0.8) {
                continue;
            }

            $intel = $this->ipIntelService->analyze($dstIp);
            $confidence = 85;
            if ($intel->isProxy === true) {
                $confidence += 5;
            }
            if ($this->containsAny(
                implode(' ', array_filter([$intel->organization, $intel->isp, $intel->reverseDns])),
                ['m247', 'ovh', 'hetzner', 'leaseweb', 'digitalocean', 'linode', 'vultr', 'datacamp']
            )) {
                $confidence += 5;
            }

            $this->ipIntelService->storeDerivedClassification($dstIp, 'vpn', min(100, $confidence), 'traffic:vpn', true);
            ++$count;
        }

        return $count;
    }

    public function detectCommonUnknownService(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT dst_ip, COUNT(DISTINCT client_id) AS clients
             FROM network_flow
             WHERE received_at >= :cutoff
               AND dst_ip IS NOT NULL
               AND dst_ip <> \'\'
               AND client_id IS NOT NULL
             GROUP BY dst_ip
             HAVING COUNT(DISTINCT client_id) >= 3',
            ['cutoff' => (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s')]
        );

        $count = 0;
        foreach ($rows as $row) {
            $ip = isset($row['dst_ip']) ? (string) $row['dst_ip'] : '';
            if ($ip === '') {
                continue;
            }

            $intel = $this->ipIntelService->analyze($ip);
            if ($intel->category !== null && strtolower($intel->category) !== 'unknown') {
                continue;
            }

            $this->ipIntelService->storeDerivedClassification($ip, 'common_service', 75, 'traffic:common_service', false);
            ++$count;
        }

        return $count;
    }

    private function incrementSummaryByCategory(TrafficAnalysisSummary $summary, ?string $category): void
    {
        if ($category === null) {
            $summary->unknown++;

            return;
        }

        switch (strtolower(trim($category))) {
            case 'torrent':
                $summary->torrent++;
                break;
            case 'vpn':
                $summary->vpn++;
                break;
            case 'hosting':
                $summary->hosting++;
                break;
            case 'google':
                $summary->google++;
                break;
            case 'meta':
                $summary->meta++;
                break;
            case 'unknown':
                $summary->unknown++;
                break;
            default:
                break;
        }
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        $haystack = strtolower($haystack);

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
