<?php

namespace App\Command;

use App\Service\NtopngClient;
use App\Service\SiteCatalogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sync-ntopng-traffic')]
class SyncNtopngTrafficCommand extends Command
{
    public function __construct(
        private readonly NtopngClient $ntopngClient,
        private readonly SiteCatalogService $siteCatalogService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->ntopngClient->syncTraffic();
        $this->siteCatalogService->enrichFromSnapshots();
        $output->writeln(sprintf('Saved %d ntopng traffic snapshots.', $count));
        return Command::SUCCESS;
    }
}
