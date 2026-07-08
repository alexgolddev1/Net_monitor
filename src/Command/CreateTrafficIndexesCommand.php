<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:create-traffic-indexes')]
class CreateTrafficIndexesCommand extends Command
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_network_flow_torrent_detector' => 'CREATE INDEX idx_network_flow_torrent_detector ON network_flow (protocol, received_at, src_ip, src_port) ALGORITHM=INPLACE, LOCK=NONE',
        'idx_network_flow_vpn_detector' => 'CREATE INDEX idx_network_flow_vpn_detector ON network_flow (client_id, received_at, dst_ip) ALGORITHM=INPLACE, LOCK=NONE',
        'idx_network_flow_common_service' => 'CREATE INDEX idx_network_flow_common_service ON network_flow (dst_ip, received_at, client_id) ALGORITHM=INPLACE, LOCK=NONE',
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Creating traffic indexes.');

        $progressBar = new ProgressBar($output, count(self::INDEXES));
        $progressBar->start();

        foreach (self::INDEXES as $indexName => $sql) {
            if ($this->indexExists($indexName)) {
                $output->writeln(sprintf('Index %s already exists, skipping.', $indexName));
                $progressBar->advance();
                continue;
            }

            $output->writeln(sprintf('Creating %s...', $indexName));
            $this->connection->executeStatement($sql);
            $output->writeln(sprintf('Created %s.', $indexName));
            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln('Traffic index creation finished.');

        return Command::SUCCESS;
    }

    private function indexExists(string $indexName): bool
    {
        $rows = $this->connection->fetchAllAssociative(
            'SHOW INDEX FROM network_flow WHERE Key_name = :indexName',
            ['indexName' => $indexName]
        );

        return $rows !== [];
    }
}
