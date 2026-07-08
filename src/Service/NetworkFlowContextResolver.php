<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class NetworkFlowContextResolver
{
    /** @var array<string, array<string, mixed>|null> */
    private array $cache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{domain: ?string, organization: ?string, app_name: ?string, protocol: ?int, src_port: ?int, dst_port: ?int}|null
     */
    public function resolveForIp(string $ip): ?array
    {
        if (isset($this->cache[$ip])) {
            return $this->cache[$ip];
        }

        $row = $this->connection->fetchAssociative(
            'SELECT domain, organization, app_name, protocol, src_port, dst_port
             FROM network_flow
             WHERE src_ip = :ip
                OR dst_ip = :ip
                OR post_nat_src_ip = :ip
                OR post_nat_dst_ip = :ip
             ORDER BY received_at DESC, id DESC
             LIMIT 1',
            ['ip' => $ip]
        );

        if (!is_array($row) || $row === []) {
            $this->cache[$ip] = null;

            return null;
        }

        $result = [
            'domain' => $this->normalizeString($row['domain'] ?? null),
            'organization' => $this->normalizeString($row['organization'] ?? null),
            'app_name' => $this->normalizeString($row['app_name'] ?? null),
            'protocol' => isset($row['protocol']) ? (int) $row['protocol'] : null,
            'src_port' => isset($row['src_port']) ? (int) $row['src_port'] : null,
            'dst_port' => isset($row['dst_port']) ? (int) $row['dst_port'] : null,
        ];

        $this->cache[$ip] = $result;

        return $result;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
