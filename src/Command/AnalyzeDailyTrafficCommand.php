<?php

namespace App\Command;

use App\Service\UnknownTrafficAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:traffic:analyze-daily')]
class AnalyzeDailyTrafficCommand extends Command
{
    public function __construct(private readonly UnknownTrafficAnalyzer $unknownTrafficAnalyzer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'lookback-hours',
            null,
            InputOption::VALUE_REQUIRED,
            'How many hours back to scan unknown IPs.',
            24
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lookbackHours = max(1, (int) $input->getOption('lookback-hours'));
        $since = new \DateTimeImmutable(sprintf('-%d hours', $lookbackHours));

        $output->writeln(sprintf(
            'Starting daily traffic analysis for the last %d hours (%s).',
            $lookbackHours,
            $since->format('Y-m-d H:i:s')
        ));

        $summary = $this->unknownTrafficAnalyzer->analyzeSince(
            $since,
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
