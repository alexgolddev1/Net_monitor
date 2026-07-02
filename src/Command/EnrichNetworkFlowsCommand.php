<?php

namespace App\Command;

use App\Service\NetworkFlowEnricher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:enrich-network-flows')]
class EnrichNetworkFlowsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly NetworkFlowEnricher $networkFlowEnricher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of flows to enrich.', 10000);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, direction, src_ip, dst_ip, protocol, src_port, dst_port, domain, app_name, domain_source
             FROM network_flow
             WHERE domain IS NULL OR app_name IS NULL
             ORDER BY id DESC
             LIMIT '.$limit
        );

        $enriched = 0;
        $domainsFound = 0;
        $this->connection->transactional(function () use ($rows, &$enriched, &$domainsFound): void {
            foreach ($rows as $row) {
                $domainWasMissing = empty($row['domain']);
                $payload = $this->networkFlowEnricher->enrich(
                    (string) ($row['direction'] ?? 'external'),
                    isset($row['src_ip']) ? (string) $row['src_ip'] : null,
                    isset($row['dst_ip']) ? (string) $row['dst_ip'] : null,
                    isset($row['protocol']) ? (int) $row['protocol'] : null,
                    isset($row['src_port']) ? (int) $row['src_port'] : null,
                    isset($row['dst_port']) ? (int) $row['dst_port'] : null,
                    isset($row['domain']) ? (string) $row['domain'] : null,
                    isset($row['domain_source']) ? (string) $row['domain_source'] : null,
                );

                if (!empty($payload['domain']) && $domainWasMissing) {
                    $domainsFound++;
                }

                $update = [
                    'app_name' => $payload['app_name'],
                    'organization' => $payload['organization'],
                ];

                if ($payload['domain'] !== null) {
                    $update['domain'] = $payload['domain'];
                }

                if ($payload['domain_source'] !== null || $domainWasMissing) {
                    $update['domain_source'] = $payload['domain_source'];
                }

                $this->connection->update('network_flow', $update, ['id' => (int) $row['id']]);
                ++$enriched;
            }
        });

        $output->writeln(sprintf('Enriched %d flows, domains found %d.', $enriched, $domainsFound));

        return Command::SUCCESS;
    }
}
