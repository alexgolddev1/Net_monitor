<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:cleanup-flows')]
class CleanupFlowsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Remove flows older than N days.', 90);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(1, (int) $input->getOption('days'));
        $before = (new \DateTimeImmutable(sprintf('-%d days', $days)))->format('Y-m-d H:i:s');
        $deleted = $this->connection->executeStatement(
            'DELETE FROM network_flow WHERE received_at < :before',
            ['before' => $before]
        );

        $output->writeln(sprintf('Deleted %d old network_flow rows.', $deleted));

        return Command::SUCCESS;
    }
}
