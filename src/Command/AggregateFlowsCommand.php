<?php

namespace App\Command;

use App\Service\TrafficAggregator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:aggregate-flows')]
class AggregateFlowsCommand extends Command
{
    public function __construct(private readonly TrafficAggregator $trafficAggregator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->trafficAggregator->aggregateDaily();

        $output->writeln(sprintf('Aggregated %d device daily rows from network_flow.', $count));
        $output->writeln(sprintf('Traffic today: %d bytes', $this->trafficAggregator->trafficTodayFromFlows()));

        $output->writeln('Top devices:');
        foreach ($this->trafficAggregator->topDevicesFromFlows() as $row) {
            $output->writeln(sprintf(
                '  #%d %s %s %d bytes',
                $row['id'],
                $row['mac'],
                $row['currentIp'] ?? '-',
                $row['totalBytes']
            ));
        }

        $output->writeln('Top clients:');
        foreach ($this->trafficAggregator->topClientsFromFlows() as $row) {
            $output->writeln(sprintf(
                '  #%d %s %s %d bytes',
                $row['id'],
                $row['fullName'] ?? 'Unlinked',
                $row['roomNumber'] ?? '-',
                $row['totalBytes']
            ));
        }

        $output->writeln('Usage by hour:');
        foreach ($this->trafficAggregator->usageByHourFromFlows() as $row) {
            $output->writeln(sprintf('  %02d:00 %d bytes', $row['hour'], $row['totalBytes']));
        }

        return Command::SUCCESS;
    }
}
