<?php

namespace App\Command;

use App\Service\DashboardCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:warm-dashboard-cache')]
class WarmDashboardCacheCommand extends Command
{
    public function __construct(private readonly DashboardCacheService $dashboardCache)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = $this->dashboardCache->refreshPayload();

        $output->writeln(sprintf(
            'Dashboard cache refreshed at %s. Traffic today: %d bytes.',
            $payload['generatedAt'] ?? '-',
            (int) ($payload['todayTraffic'] ?? 0)
        ));

        return Command::SUCCESS;
    }
}
