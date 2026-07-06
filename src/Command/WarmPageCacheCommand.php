<?php

namespace App\Command;

use App\Service\DashboardCacheService;
use App\Service\PageCacheService;
use App\Service\TrafficAggregator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:warm-page-cache')]
class WarmPageCacheCommand extends Command
{
    public function __construct(
        private readonly DashboardCacheService $dashboardCache,
        private readonly PageCacheService $pageCache,
        private readonly TrafficAggregator $trafficAggregator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Syncing rollups...');
        $synced = $this->trafficAggregator->aggregateIncremental();
        $output->writeln(sprintf('Rollups synced from %d flow rows.', $synced));

        $output->writeln('Refreshing dashboard cache...');
        $dashboardStartedAt = microtime(true);
        $dashboard = $this->dashboardCache->refreshPayload();
        $output->writeln(sprintf('Dashboard cache done in %.2fs.', microtime(true) - $dashboardStartedAt));

        $output->writeln('Refreshing page cache...');
        $pageStartedAt = microtime(true);
        $pages = $this->pageCache->refresh();
        $output->writeln(sprintf('Page cache done in %.2fs.', microtime(true) - $pageStartedAt));

        $output->writeln(sprintf(
            'Page cache refreshed at %s. Dashboard traffic: %d bytes. Devices: %d. Clients: %d.',
            $dashboard['generatedAt'] ?? '-',
            (int) ($dashboard['todayTraffic'] ?? 0),
            $pages['devices'],
            $pages['clients']
        ));

        return Command::SUCCESS;
    }
}
