<?php

namespace App\Service;

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

        foreach ($leases as $lease) {
            $mac = strtoupper((string) ($lease['mac'] ?? ''));
            $ip = (string) ($lease['ip'] ?? '');
            if (!$mac || !$ip) {
                continue;
            }

            $device = $this->resolver->resolve($mac, $ip);
            $device
                ->setHostname($lease['hostname'] ?? $device->getHostname())
                ->setVendor($lease['vendor'] ?? $device->getVendor())
                ->setVlan($lease['vlan'] ?? $device->getVlan())
                ->setDeviceName($lease['comment'] ?? $device->getDeviceName());
            $this->resolver->recordIp($device, $ip);
            ++$count;
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

            $rows = $this->routerOsPrint($socket, '/ip/dhcp-server/lease/print');
            fclose($socket);

            return array_values(array_filter(array_map(
                fn (array $row) => $this->normalizeLeaseRow($row),
                $rows
            )));
        } catch (\Throwable $e) {
            $this->logger->error('MikroTik sync failed', ['exception' => $e]);
        }

        return [];
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

            $type = $sentence[0] ?? null;
            if ($type === '!re') {
                $rows[] = $this->parseRouterOsSentence($sentence);
                continue;
            }

            if ($type === '!trap' || $type === '!fatal') {
                $this->logger->warning('MikroTik API command failed', ['command' => $command, 'reply' => $this->parseRouterOsSentence($sentence)]);
                return [];
            }

            if ($type === '!done') {
                break;
            }
        }

        return $rows;
    }

    private function isDone(array $reply): bool
    {
        return ($reply['type'] ?? null) === '!done';
    }

    private function readResponse($socket): array
    {
        while (true) {
            $sentence = $this->readSentence($socket);
            if ($sentence === []) {
                return [];
            }

            $type = $sentence[0] ?? null;
            if ($type === '!re') {
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
            $result['type'] = $sentence[0];
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
}
