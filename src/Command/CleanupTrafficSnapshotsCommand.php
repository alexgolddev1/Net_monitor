<?php

namespace App\Command;

use App\Service\TrafficAggregator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:cleanup-traffic-snapshots')]
class CleanupTrafficSnapshotsCommand extends Command
{
    public function __construct(private readonly TrafficAggregator $trafficAggregator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep snapshots for N days', 35);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->trafficAggregator->cleanupSnapshots((int) $input->getOption('days'));
        $output->writeln(sprintf('Deleted %d old snapshots.', $deleted));
        return Command::SUCCESS;
    }
}
