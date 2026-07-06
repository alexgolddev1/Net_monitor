<?php

namespace App\Command;

use App\Service\DashboardCacheService;
use App\Service\TrafficAggregator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:warm-dashboard-cache')]
class WarmDashboardCacheCommand extends Command
{
    public function __construct(
        private readonly DashboardCacheService $dashboardCache,
        private readonly TrafficAggregator $trafficAggregator,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Syncing rollups...');
        $synced = $this->trafficAggregator->aggregateIncremental();
        $output->writeln(sprintf('Rollups synced from %d flow rows.', $synced));

        $output->writeln('Refreshing dashboard cache...');
        $startedAt = microtime(true);
        $payload = $this->dashboardCache->refreshPayload();

        $output->writeln(sprintf(
            'Dashboard cache refreshed at %s. Traffic today: %d bytes. Took %.2fs.',
            $payload['generatedAt'] ?? '-',
            (int) ($payload['todayTraffic'] ?? 0),
            microtime(true) - $startedAt
        ));

        return Command::SUCCESS;
    }
}
