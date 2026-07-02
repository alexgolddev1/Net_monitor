<?php

namespace App\Command;

use App\NetFlow\NetFlowV9Parser;
use App\NetFlow\ParsedFlow;
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

    public function __construct(private readonly NetFlowV9Parser $parser)
    {
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
                    $output->writeln(sprintf('Parsed %d flows from %s', count($flows), $senderIp));
                    foreach (array_slice($flows, 0, 5) as $flow) {
                        $output->writeln($this->formatFlow($flow));
                    }
                } elseif ($this->parser->parsedTemplatesInLastPacket() > 0) {
                    $output->writeln('Parsed templates, no data flows yet');
                } else {
                    $output->writeln(sprintf('Parsed 0 flows from %s', $senderIp));
                }
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<comment>NetFlow parsing warning: %s</comment>', $exception->getMessage()));
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
