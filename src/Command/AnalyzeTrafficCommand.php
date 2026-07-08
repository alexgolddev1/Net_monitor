<?php

namespace App\Command;

use App\Service\UnknownTrafficAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:traffic:analyze')]
class AnalyzeTrafficCommand extends Command
{
    public function __construct(private readonly UnknownTrafficAnalyzer $unknownTrafficAnalyzer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $summary = $this->unknownTrafficAnalyzer->analyze(
            static function (string $message) use ($output): void {
                $output->writeln($message);
            }
        );

        $output->writeln(sprintf('External IPs refreshed: %d', $summary->refreshedIps));
        $output->writeln(sprintf('Torrent: %d', $summary->torrent));
        $output->writeln(sprintf('VPN: %d', $summary->vpn));
        $output->writeln(sprintf('Hosting: %d', $summary->hosting));
        $output->writeln(sprintf('Google: %d', $summary->google));
        $output->writeln(sprintf('Meta: %d', $summary->meta));
        $output->writeln(sprintf('Unknown: %d', $summary->unknown));

        return Command::SUCCESS;
    }
}
