<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:create-traffic-indexes')]
class CreateTrafficIndexesCommand extends Command
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_network_flow_torrent_detector' => 'ALTER TABLE network_flow ADD INDEX idx_network_flow_torrent_detector (protocol, received_at, src_ip, src_port), ALGORITHM=INPLACE, LOCK=NONE',
        'idx_network_flow_vpn_detector' => 'ALTER TABLE network_flow ADD INDEX idx_network_flow_vpn_detector (client_id, received_at, dst_ip), ALGORITHM=INPLACE, LOCK=NONE',
        'idx_network_flow_common_service' => 'ALTER TABLE network_flow ADD INDEX idx_network_flow_common_service (dst_ip, received_at, client_id), ALGORITHM=INPLACE, LOCK=NONE',
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Creating traffic indexes.');

        foreach (self::INDEXES as $indexName => $sql) {
            if ($this->indexExists($indexName)) {
                $output->writeln(sprintf('Index %s already exists, skipping.', $indexName));
                continue;
            }

            $busyQueries = $this->busyNetworkFlowQueries();
            if ($busyQueries !== []) {
                $output->writeln(sprintf(
                    'network_flow is busy, skipping %s to avoid metadata lock.',
                    $indexName
                ));
                foreach ($busyQueries as $query) {
                    $output->writeln(sprintf(
                        '  id=%d state=%s time=%d info=%s',
                        $query['id'],
                        $query['state'] ?? '-',
                        $query['time'] ?? 0,
                        $query['info'] ?? '-'
                    ));
                }
                $output->writeln('Stop writers like netflow-worker and rerun this command.');

                return Command::FAILURE;
            }

            $output->writeln(sprintf('Creating %s...', $indexName));
            $output->writeln(sprintf('SQL: %s', $sql));

            try {
                $this->connection->executeStatement($sql);
                $output->writeln(sprintf('Created %s.', $indexName));
            } catch (DBALException $e) {
                $output->writeln(sprintf('Failed %s: %s', $indexName, $e->getMessage()));
                return Command::FAILURE;
            }
        }

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

    /**
     * @return list<array{id: int, state: ?string, time: int, info: ?string}>
     */
    private function busyNetworkFlowQueries(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ID, STATE, TIME, INFO
             FROM information_schema.PROCESSLIST
             WHERE DB = DATABASE()
               AND ID <> CONNECTION_ID()
               AND COMMAND = \'Query\'
               AND (
                    INFO LIKE \'%network_flow%\'
                    OR STATE LIKE \'%metadata lock%\'
                    OR STATE LIKE \'%Sending data%\'
                    OR STATE LIKE \'%Creating index%\'
                    OR STATE LIKE \'%altering table%\'
               )
             ORDER BY TIME DESC'
        );

        $busy = [];
        foreach ($rows as $row) {
            $busy[] = [
                'id' => (int) ($row['ID'] ?? 0),
                'state' => isset($row['STATE']) ? (string) $row['STATE'] : null,
                'time' => (int) ($row['TIME'] ?? 0),
                'info' => isset($row['INFO']) ? (string) $row['INFO'] : null,
            ];
        }

        return $busy;
    }
}
