<?php

namespace App\Command;

use App\Service\MikroTikClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sync-mikrotik-leases')]
class SyncMikroTikLeasesCommand extends Command
{
    public function __construct(private readonly MikroTikClient $mikroTikClient)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->mikroTikClient->syncLeases();
        $output->writeln(sprintf('Synced %d MikroTik leases.', $count));
        return Command::SUCCESS;
    }
}
