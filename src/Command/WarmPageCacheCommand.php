<?php

namespace App\Command;

use App\Service\DashboardCacheService;
use App\Service\PageCacheService;
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dashboard = $this->dashboardCache->refreshPayload();
        $pages = $this->pageCache->refresh();

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
