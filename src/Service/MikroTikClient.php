<?php

namespace App\Service;

use App\Entity\DnsCacheRecord;
use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MikroTikClient
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientResolver $resolver,
        private readonly LoggerInterface $logger,
        private readonly bool $mockNetworkData,
    ) {
    }

    public function syncLeases(): int
    {
        $leases = $this->mockNetworkData ? $this->mockLeases() : $this->fetchLeases();
        $count = 0;
        $processedMacs = [];

        foreach ($leases as $lease) {
            $mac = strtoupper((string) ($lease['mac'] ?? ''));
            $ip = (string) ($lease['ip'] ?? '');
            if (!$mac || !$ip) {
                continue;
            }

            $status = strtolower((string) ($lease['status'] ?? ''));
            $device = $processedMacs[$mac] ?? null;

            if ($device) {
                $this->logger->warning('Duplicate MikroTik lease MAC encountered', [
                    'mac' => $mac,
                    'ip' => $ip,
                    'status' => $status,
                ]);
                if ($status === 'bound') {
                    $this->applyLeaseData($device, $lease);
                }
            } else {
                $device = $this->resolver->resolve($mac, $ip);
                $processedMacs[$mac] = $device;
                $this->applyLeaseData($device, $lease);
            }

            $this->resolver->recordIp($device, $ip);
            ++$count;
        }

        $this->em->flush();

        return $count;
    }

    public function syncDnsCache(): int
    {
        $rows = $this->mockNetworkData ? $this->mockDnsCache() : $this->fetchDnsCache();
        $count = 0;
        $now = new \DateTimeImmutable();

        foreach ($rows as $row) {
            try {
                $domain = $row['domain'] ?? null;
                $recordType = $row['recordType'] ?? null;

                if (!is_string($domain) || $domain === '' || !is_string($recordType) || $recordType === '') {
                    continue;
                }

                $criteria = ['domain' => $domain, 'recordType' => strtoupper($recordType)];
                if (($row['resolvedIp'] ?? null) !== null) {
                    $criteria['resolvedIp'] = $row['resolvedIp'];
                }
                if (($row['cname'] ?? null) !== null) {
                    $criteria['cname'] = $row['cname'];
                }

                $record = $this->em->getRepository(DnsCacheRecord::class)->findOneBy($criteria);
                if (!$record) {
                    $record = (new DnsCacheRecord())
                        ->setDomain($domain)
                        ->setRecordType($recordType)
                        ->setFirstSeenAt($now)
                        ->setSource('mikrotik_cache');
                    $this->em->persist($record);
                }

                $record
                    ->setDomain($domain)
                    ->setRecordType($recordType)
                    ->setResolvedIp($row['resolvedIp'] ?? null)
                    ->setCname($row['cname'] ?? null)
                    ->setTtl($row['ttl'] ?? null)
                    ->setLastSeenAt($now)
                    ->setSource('mikrotik_cache');

                if (!$record->getFirstSeenAt()) {
                    $record->setFirstSeenAt($now);
                }

                ++$count;
            } catch (\Throwable $e) {
                $this->logger->warning('Skipping bad MikroTik DNS cache row', [
                    'row' => $row,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        return $count;
    }

    /**
     * Connects to RouterOS API, authenticates, and reads DHCP leases.
     * Errors are logged and swallowed so sync jobs stay non-fatal.
     */
    private function fetchLeases(): array
    {
        try {
            $rows = $this->fetchRouterOsRows('/ip/dhcp-server/lease/print');

            return array_values(array_filter(array_map(
                fn (array $row) => $this->normalizeLeaseRow($row),
                $rows
            )));
        } catch (\Throwable $e) {
            $this->logger->error('MikroTik sync failed', ['exception' => $e]);
        }

        return [];
    }

    private function fetchDnsCache(): array
    {
        try {
            $rows = $this->fetchRouterOsRows('/ip/dns/cache/print');

            return array_values(array_filter(array_map(
                fn (array $row) => $this->normalizeDnsCacheRow($row),
                $rows
            )));
        } catch (\Throwable $e) {
            $this->logger->error('MikroTik DNS cache sync failed', ['exception' => $e]);
        }

        return [];
    }

    private function applyLeaseData(Device $device, array $lease): void
    {
        $hostname = $lease['hostname'] ?? null;
        if ($hostname !== null) {
            $device->setHostname($hostname);
        }

        $vendor = $lease['vendor'] ?? null;
        if ($vendor !== null) {
            $device->setVendor($vendor);
        }

        $vlan = $lease['vlan'] ?? null;
        if ($vlan !== null) {
            $device->setVlan($vlan);
        }

        $comment = $lease['comment'] ?? null;
        if ($comment !== null) {
            $device->setDeviceName($comment);
        }

        $ip = $lease['ip'] ?? null;
        if ($ip !== null) {
            $device->setCurrentIp($ip);
        }
    }

    private function normalizeLeaseRow(array $row): ?array
    {
        $mac = $row['mac-address'] ?? null;
        $ip = $row['address'] ?? null;

        if (!$mac || !$ip) {
            return null;
        }

        return [
            '_id' => $row['.id'] ?? null,
            'mac' => strtoupper((string) $mac),
            'ip' => (string) $ip,
            'hostname' => $row['host-name'] ?? null,
            'vlan' => $row['server'] ?? null,
            'status' => $row['status'] ?? null,
            'dynamic' => isset($row['dynamic']) ? filter_var($row['dynamic'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null,
            'comment' => $row['comment'] ?? null,
            'vendor' => null,
        ];
    }

    private function normalizeDnsCacheRow(array $row): ?array
    {
        $domain = $this->normalizeDnsName($row['name'] ?? null);
        $recordType = strtoupper(trim((string) ($row['type'] ?? '')));

        if ($domain === null || !in_array($recordType, ['A', 'AAAA', 'CNAME'], true)) {
            return null;
        }

        $data = $this->normalizeDnsName($row['data'] ?? null);

        return [
            'domain' => $domain,
            'recordType' => $recordType,
            'resolvedIp' => in_array($recordType, ['A', 'AAAA'], true) ? $data : null,
            'cname' => $recordType === 'CNAME' ? $data : null,
            'ttl' => $this->parseDnsTtl($row['ttl'] ?? null),
        ];
    }

    private function fetchRouterOsRows(string $command): array
    {
        $host = trim((string) ($_ENV['MIKROTIK_HOST'] ?? ''));
        $port = (int) ($_ENV['MIKROTIK_PORT'] ?? 8728);
        $user = (string) ($_ENV['MIKROTIK_USER'] ?? '');
        $password = (string) ($_ENV['MIKROTIK_PASSWORD'] ?? '');

        if ($host === '' || $user === '') {
            $this->logger->warning('MikroTik API credentials are not configured');

            return [];
        }

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT
        );
        if (!$socket) {
            $this->logger->warning('MikroTik API connection failed', [
                'host' => $host,
                'port' => $port,
                'error' => $errstr,
                'code' => $errno,
            ]);

            return [];
        }

        stream_set_timeout($socket, 5);

        if (!$this->routerOsLogin($socket, $user, $password)) {
            fclose($socket);

            return [];
        }

        try {
            return $this->routerOsPrint($socket, $command);
        } finally {
            fclose($socket);
        }
    }

    private function routerOsLogin($socket, string $user, string $password): bool
    {
        $this->writeSentence($socket, ['/login', sprintf('=name=%s', $user), sprintf('=password=%s', $password)]);
        $reply = $this->readResponse($socket);
        if ($this->isDone($reply)) {
            return true;
        }

        $challenge = $reply['ret'] ?? null;
        if (!is_string($challenge) || $challenge === '') {
            $this->logger->warning('MikroTik API login failed', ['reply' => $reply]);
            return false;
        }

        $response = '00'.md5(chr(0).$password.pack('H*', $challenge));
        $this->writeSentence($socket, ['/login', sprintf('=name=%s', $user), sprintf('=response=%s', $response)]);
        $reply = $this->readResponse($socket);
        if ($this->isDone($reply)) {
            return true;
        }

        $this->logger->warning('MikroTik API challenge login failed', ['reply' => $reply]);
        return false;
    }

    private function routerOsPrint($socket, string $command): array
    {
        $this->writeSentence($socket, [$command]);
        $rows = [];

        while (true) {
            $sentence = $this->readSentence($socket);
            if ($sentence === []) {
                break;
            }

            $replyType = $sentence[0] ?? null;
            if ($replyType === '!re') {
                $rows[] = $this->parseRouterOsSentence($sentence);
                continue;
            }

            if ($replyType === '!trap' || $replyType === '!fatal') {
                $this->logger->warning('MikroTik API command failed', ['command' => $command, 'reply' => $this->parseRouterOsSentence($sentence)]);
                return [];
            }

            if ($replyType === '!done') {
                break;
            }
        }

        return $rows;
    }

    private function isDone(array $reply): bool
    {
        return ($reply['_replyType'] ?? null) === '!done';
    }

    private function readResponse($socket): array
    {
        while (true) {
            $sentence = $this->readSentence($socket);
            if ($sentence === []) {
                return [];
            }

            $replyType = $sentence[0] ?? null;
            if ($replyType === '!re') {
                continue;
            }

            return $this->parseRouterOsSentence($sentence);
        }
    }

    private function readSentence($socket): array
    {
        $words = [];

        while (true) {
            $word = $this->readWord($socket);
            if ($word === null) {
                return $words;
            }
            if ($word === '') {
                break;
            }
            $words[] = $word;
        }

        return $words;
    }

    private function readWord($socket): ?string
    {
        $length = $this->readLength($socket);
        if ($length === null) {
            return null;
        }
        if ($length === 0) {
            return '';
        }

        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('RouterOS API read timed out');
                }
                throw new \RuntimeException('RouterOS API connection closed unexpectedly');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function readLength($socket): ?int
    {
        $byte = fread($socket, 1);
        if ($byte === false || $byte === '') {
            return null;
        }

        $first = ord($byte);
        if (($first & 0x80) === 0x00) {
            return $first;
        }
        if (($first & 0xC0) === 0x80) {
            $second = ord($this->readBytes($socket, 1));
            return (($first & 0x3F) << 8) + $second;
        }
        if (($first & 0xE0) === 0xC0) {
            $next = $this->readBytes($socket, 2);
            return (($first & 0x1F) << 16) + (ord($next[0]) << 8) + ord($next[1]);
        }
        if (($first & 0xF0) === 0xE0) {
            $next = $this->readBytes($socket, 3);
            return (($first & 0x0F) << 24) + (ord($next[0]) << 16) + (ord($next[1]) << 8) + ord($next[2]);
        }

        $next = $this->readBytes($socket, 4);
        return (ord($next[0]) << 24) + (ord($next[1]) << 16) + (ord($next[2]) << 8) + ord($next[3]);
    }

    private function readBytes($socket, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('RouterOS API read timed out');
                }
                throw new \RuntimeException('RouterOS API connection closed unexpectedly');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function writeSentence($socket, array $words): void
    {
        foreach ($words as $word) {
            fwrite($socket, $this->encodeLength(strlen($word)).$word);
        }

        fwrite($socket, "\x00");
        fflush($socket);
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            $length |= 0x8000;
            return pack('n', $length);
        }
        if ($length < 0x200000) {
            $length |= 0xC00000;
            return chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }
        if ($length < 0x10000000) {
            $length |= 0xE0000000;
            return pack('N', $length);
        }

        return "\xF0".pack('N', $length);
    }

    private function parseRouterOsSentence(array $sentence): array
    {
        $result = [];
        foreach ($sentence as $word) {
            if ($word === '' || $word[0] !== '=') {
                continue;
            }
            $payload = substr($word, 1);
            $pos = strpos($payload, '=');
            if ($pos === false) {
                continue;
            }

            $key = substr($payload, 0, $pos);
            $value = substr($payload, $pos + 1);
            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        if (isset($sentence[0])) {
            $result['_replyType'] = $sentence[0];
        }

        return $result;
    }

    private function mockLeases(): array
    {
        return [
            ['mac' => 'AA:10:00:00:00:01', 'ip' => '192.168.10.11', 'hostname' => 'pc-accounting-1', 'vendor' => 'Dell', 'vlan' => '10', 'comment' => 'Accounting PC'],
            ['mac' => 'AA:10:00:00:00:02', 'ip' => '192.168.10.12', 'hostname' => 'printer-203', 'vendor' => 'HP', 'vlan' => '10', 'comment' => 'Office printer'],
            ['mac' => 'AA:20:00:00:00:03', 'ip' => '192.168.20.21', 'hostname' => 'lab-laptop-1', 'vendor' => 'Lenovo', 'vlan' => '20', 'comment' => 'Lab laptop'],
            ['mac' => 'AA:30:00:00:00:04', 'ip' => '192.168.30.31', 'hostname' => 'phone-reception', 'vendor' => 'Apple', 'vlan' => '30', 'comment' => 'Reception phone'],
            ['mac' => 'AA:40:00:00:00:05', 'ip' => '192.168.40.41', 'hostname' => 'guest-tablet', 'vendor' => 'Samsung', 'vlan' => '40', 'comment' => 'Guest device'],
        ];
    }

    private function mockDnsCache(): array
    {
        return [
            ['domain' => 'youtube.com', 'recordType' => 'A', 'resolvedIp' => '142.250.185.238', 'cname' => null, 'ttl' => 300],
            ['domain' => 'googlevideo.com', 'recordType' => 'A', 'resolvedIp' => '142.250.185.239', 'cname' => null, 'ttl' => 300],
            ['domain' => 'facebook.com', 'recordType' => 'A', 'resolvedIp' => '157.240.229.35', 'cname' => null, 'ttl' => 300],
            ['domain' => 'instagram.com', 'recordType' => 'A', 'resolvedIp' => '157.240.229.174', 'cname' => null, 'ttl' => 300],
            ['domain' => 'office.com', 'recordType' => 'CNAME', 'resolvedIp' => null, 'cname' => 'office.com', 'ttl' => 300],
        ];
    }

    private function normalizeDnsName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        $value = rtrim($value, '.');

        return $value === '' ? null : $value;
    }

    private function parseDnsTtl(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }

            if (ctype_digit($value)) {
                return (int) $value;
            }

            if (preg_match_all('/(\d+)([smhdw])/', strtolower($value), $matches, PREG_SET_ORDER) === false) {
                return null;
            }

            $seconds = 0;
            foreach ($matches as $match) {
                $amount = (int) $match[1];
                $seconds += match ($match[2]) {
                    's' => $amount,
                    'm' => $amount * 60,
                    'h' => $amount * 3600,
                    'd' => $amount * 86400,
                    'w' => $amount * 604800,
                    default => 0,
                };
            }

            return $seconds > 0 ? $seconds : null;
        }

        return null;
    }
}
