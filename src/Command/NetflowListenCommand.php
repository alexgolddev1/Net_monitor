<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:netflow:listen')]
class NetflowListenCommand extends Command
{
    private bool $running = true;

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

            $output->writeln(sprintf(
                'Packet size=%d sender=%s first20=%s',
                strlen($packet),
                $sender ?? 'unknown',
                bin2hex(substr($packet, 0, 20))
            ));
        }

        fclose($socket);

        return Command::SUCCESS;
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
