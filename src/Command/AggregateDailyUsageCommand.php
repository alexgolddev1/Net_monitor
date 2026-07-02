<?php

namespace App\Command;

use App\Service\TrafficAggregator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:aggregate-daily-usage')]
class AggregateDailyUsageCommand extends Command
{
    public function __construct(private readonly TrafficAggregator $trafficAggregator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->trafficAggregator->aggregateDaily();
        $output->writeln(sprintf('Aggregated %d device daily rows from network_flow.', $count));
        return Command::SUCCESS;
    }
}
