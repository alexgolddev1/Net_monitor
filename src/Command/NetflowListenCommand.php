<?php

namespace App\Command;

use App\NetFlow\NetFlowV9Parser;
use App\NetFlow\ParsedFlow;
use App\Service\NetworkFlowEnricher;
use Doctrine\DBAL\Connection;
use Throwable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:netflow:listen')]
class NetflowListenCommand extends Command
{
    private bool $running = true;

    /** @var list<array{network: int, mask: int}> */
    private array $localNetworks;

    /** @var array<string, array{device_id: int|null, client_id: int|null}> */
    private array $deviceCacheByIp = [];

    public function __construct(
        private readonly NetFlowV9Parser $parser,
        private readonly Connection $connection,
        private readonly NetworkFlowEnricher $networkFlowEnricher,
        string $localSubnets,
    ) {
        $this->localNetworks = $this->parseLocalSubnets($localSubnets);

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'UDP host to listen on.', '0.0.0.0')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'UDP port to listen on.', '2055');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');

        if ($port < 1 || $port > 65535) {
            $output->writeln('<error>Port must be between 1 and 65535.</error>');

            return Command::INVALID;
        }

        $this->registerSignalHandlers($output);

        $socket = @stream_socket_server(
            sprintf('udp://%s:%d', $host, $port),
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND
        );

        if ($socket === false) {
            $output->writeln(sprintf('<error>Unable to listen on %s:%d: %s (%d)</error>', $host, $port, $errorMessage, $errorCode));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Listening NetFlow on %s:%d', $host, $port));

        while ($this->running) {
            $read = [$socket];
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);

            if ($changed === false || $changed === 0) {
                continue;
            }

            $sender = null;
            $packet = stream_socket_recvfrom($socket, 65535, 0, $sender);

            if ($packet === false) {
                continue;
            }

            $senderAddress = $sender ?? 'unknown';
            $senderIp = $this->extractSenderIp($senderAddress);

            $output->writeln(sprintf(
                'Packet size=%d sender=%s first20=%s',
                strlen($packet),
                $senderAddress,
                bin2hex(substr($packet, 0, 20))
            ));

            try {
                $flows = $this->parser->parse($packet, $senderIp);

                foreach ($this->parser->lastWarnings() as $warning) {
                    $output->writeln(sprintf('<comment>%s</comment>', $warning));
                }

                if (count($flows) > 0) {
                    $saved = $this->saveFlows($flows);
                    $output->writeln(sprintf('Parsed %d flows from %s', count($flows), $senderIp));
                    $output->writeln(sprintf('Saved %d flows to network_flow', $saved));
                    foreach (array_slice($flows, 0, 5) as $flow) {
                        $output->writeln($this->formatFlow($flow));
                    }
                } elseif ($this->parser->parsedTemplatesInLastPacket() > 0) {
                    $output->writeln('Parsed templates, no data flows yet');
                } else {
                    $output->writeln(sprintf('Parsed 0 flows from %s', $senderIp));
                }
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<comment>NetFlow handling warning: %s</comment>', $exception->getMessage()));
            }
        }

        fclose($socket);

        return Command::SUCCESS;
    }

    private function extractSenderIp(string $sender): string
    {
        if ($sender === 'unknown') {
            return $sender;
        }

        if (str_starts_with($sender, '[')) {
            $closingBracketPosition = strpos($sender, ']');

            return $closingBracketPosition === false ? $sender : substr($sender, 1, $closingBracketPosition - 1);
        }

        $lastColonPosition = strrpos($sender, ':');

        return $lastColonPosition === false ? $sender : substr($sender, 0, $lastColonPosition);
    }

    private function formatFlow(ParsedFlow $flow): string
    {
        return sprintf(
            'flow src=%s dst=%s bytes=%s packets=%s protocol=%s srcPort=%s dstPort=%s inputInterface=%s outputInterface=%s firstSeen=%s lastSeen=%s',
            $flow->srcIPv4 ?? '-',
            $flow->dstIPv4 ?? '-',
            $this->formatNullableInt($flow->bytes),
            $this->formatNullableInt($flow->packets),
            $this->formatNullableInt($flow->protocol),
            $this->formatNullableInt($flow->srcPort),
            $this->formatNullableInt($flow->dstPort),
            $this->formatNullableInt($flow->inputInterface),
            $this->formatNullableInt($flow->outputInterface),
            $flow->firstSeen?->format('Y-m-d H:i:s.u') ?? '-',
            $flow->lastSeen?->format('Y-m-d H:i:s.u') ?? '-',
        );
    }

    private function formatNullableInt(?int $value): string
    {
        return $value === null ? '-' : (string) $value;
    }

    /**
     * @param list<ParsedFlow> $flows
     */
    private function saveFlows(array $flows): int
    {
        if ($flows === []) {
            return 0;
        }

        $receivedAt = new \DateTimeImmutable();
        $saved = 0;

        $this->connection->transactional(function () use ($flows, $receivedAt, &$saved): void {
            foreach ($flows as $flow) {
                $direction = $this->detectDirection($flow);
                $deviceMatch = $this->matchDevice($flow, $direction);
                $enrichment = $this->networkFlowEnricher->enrich(
                    $direction,
                    $flow->srcIPv4,
                    $flow->dstIPv4,
                    $flow->protocol,
                    $flow->srcPort,
                    $flow->dstPort,
                );

                $this->connection->insert('network_flow', [
                    'exporter_ip' => $flow->exporterIp,
                    'src_ip' => $flow->srcIPv4,
                    'dst_ip' => $flow->dstIPv4,
                    'src_port' => $flow->srcPort,
                    'dst_port' => $flow->dstPort,
                    'protocol' => $flow->protocol,
                    'bytes' => $flow->bytes,
                    'packets' => $flow->packets,
                    'input_interface' => $flow->inputInterface,
                    'output_interface' => $flow->outputInterface,
                    'first_seen_at' => $this->formatDateTime($flow->firstSeen),
                    'last_seen_at' => $this->formatDateTime($flow->lastSeen),
                    'received_at' => $this->formatDateTime($receivedAt),
                    'device_id' => $deviceMatch['device_id'],
                    'client_id' => $deviceMatch['client_id'],
                    'direction' => $direction,
                    'domain' => $enrichment['domain'],
                    'app_name' => $enrichment['app_name'],
                    'organization' => $enrichment['organization'],
                    'domain_source' => $enrichment['domain_source'],
                ]);
                $saved++;
            }
        });

        return $saved;
    }

    private function detectDirection(ParsedFlow $flow): string
    {
        $srcLocal = $flow->srcIPv4 !== null && $this->isLocalIp($flow->srcIPv4);
        $dstLocal = $flow->dstIPv4 !== null && $this->isLocalIp($flow->dstIPv4);

        if ($srcLocal && !$dstLocal) {
            return 'upload';
        }

        if ($dstLocal && !$srcLocal) {
            return 'download';
        }

        if ($srcLocal && $dstLocal) {
            return 'local';
        }

        return 'external';
    }

    /**
     * @return array{device_id: int|null, client_id: int|null}
     */
    private function matchDevice(ParsedFlow $flow, string $direction): array
    {
        $ip = match ($direction) {
            'upload' => $flow->srcIPv4,
            'download' => $flow->dstIPv4,
            'local' => $flow->srcIPv4 ?? $flow->dstIPv4,
            default => null,
        };

        if ($ip === null) {
            return ['device_id' => null, 'client_id' => null];
        }

        if (array_key_exists($ip, $this->deviceCacheByIp)) {
            return $this->deviceCacheByIp[$ip];
        }

        $row = $this->connection->fetchAssociative(
            'SELECT d.id device_id, d.client_id client_id FROM device d WHERE d.current_ip = :ip LIMIT 1',
            ['ip' => $ip]
        );

        $match = [
            'device_id' => $row === false ? null : (int) $row['device_id'],
            'client_id' => $row === false || $row['client_id'] === null ? null : (int) $row['client_id'],
        ];
        $this->deviceCacheByIp[$ip] = $match;

        return $match;
    }

    private function formatDateTime(?\DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    /**
     * @return list<array{network: int, mask: int}>
     */
    private function parseLocalSubnets(string $localSubnets): array
    {
        $networks = [];

        foreach (array_filter(array_map('trim', explode(',', $localSubnets))) as $cidr) {
            [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, '32');
            $networkLong = ip2long($network);

            if ($networkLong === false || !is_numeric($prefix)) {
                continue;
            }

            $prefixLength = max(0, min(32, (int) $prefix));
            $mask = $prefixLength === 0 ? 0 : ((-1 << (32 - $prefixLength)) & 0xFFFFFFFF);
            $networks[] = [
                'network' => ip2long(long2ip($networkLong & $mask)),
                'mask' => $mask,
            ];
        }

        return $networks;
    }

    private function isLocalIp(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach ($this->localNetworks as $network) {
            if (($ipLong & $network['mask']) === $network['network']) {
                return true;
            }
        }

        return false;
    }

    private function registerSignalHandlers(OutputInterface $output): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () use ($output): void {
            $this->running = false;
            $output->writeln('');
            $output->writeln('Stopping NetFlow listener...');
        });

        pcntl_signal(SIGTERM, function () use ($output): void {
            $this->running = false;
            $output->writeln('');
            $output->writeln('Stopping NetFlow listener...');
        });
    }
}
