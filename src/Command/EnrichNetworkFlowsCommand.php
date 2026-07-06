<?php

namespace App\Command;

use App\Service\NetworkFlowEnricher;
use App\Service\AppClassifier;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly AppClassifier $appClassifier,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of flows to enrich.', 10000)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows to process per batch.', 500)
            ->addOption('refresh-domains', null, InputOption::VALUE_NONE, 'Recheck DNS cache domains for already enriched flows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $batchSize = max(1, min($limit, (int) $input->getOption('batch-size')));
        $refreshDomains = (bool) $input->getOption('refresh-domains');

        $processed = 0;
        $enriched = 0;
        $domainsFound = 0;
        $dnsMatchesFound = 0;
        $lastId = null;

        while ($processed < $limit) {
            $currentBatchSize = min($batchSize, $limit - $processed);
            $rows = $this->fetchFlowBatch($currentBatchSize, $lastId, $refreshDomains);
            if ($rows === []) {
                break;
            }

            $lastId = (int) end($rows)['id'];
            $dnsByIp = $this->fetchDnsMatchesByIp($rows);
            $dnsMatchesFound += count($dnsByIp);

            $this->connection->transactional(function () use ($rows, $dnsByIp, &$enriched, &$domainsFound): void {
                foreach ($rows as $row) {
                    $domainWasMissing = empty($row['domain']);
                    $domainFromDns = $this->domainForFlow($row, $dnsByIp);
                    $domain = $domainFromDns;

                    if ($domain === null) {
                        $domain = $this->normalizeDomain(isset($row['domain']) ? (string) $row['domain'] : null);
                    }

                    if ($domain !== null && $domainWasMissing) {
                        $domainsFound++;
                    }

                    $appName = $this->appClassifier->classify(
                        $domain,
                        isset($row['protocol']) ? (int) $row['protocol'] : null,
                        isset($row['src_port']) ? (int) $row['src_port'] : null,
                        isset($row['dst_port']) ? (int) $row['dst_port'] : null,
                    );

                    $update = [
                        'app_name' => $appName,
                        'organization' => $this->appClassifier->organizationForDomain($domain),
                    ];

                    if ($domain !== null) {
                        $update['domain'] = $domain;
                    }

                    if ($domainFromDns !== null) {
                        $update['domain_source'] = 'dns_cache';
                    } elseif ($domain !== null && $domainWasMissing) {
                        $update['domain_source'] = 'dns_cache';
                    } elseif ($domainWasMissing) {
                        $update['domain_source'] = null;
                    }

                    $this->connection->update('network_flow', $update, ['id' => (int) $row['id']]);
                    ++$enriched;
                }
            });

            $processed += count($rows);
            $this->em->clear();

            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    'Processed batch: rows=%d dns_matches=%d updated_total=%d domains_found_total=%d',
                    count($rows),
                    count($dnsByIp),
                    $enriched,
                    $domainsFound
                ));
            }
        }

        $output->writeln(sprintf(
            'Enriched %d flows, DNS matches %d, domains found %d.',
            $enriched,
            $dnsMatchesFound,
            $domainsFound
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchFlowBatch(int $limit, ?int $lastId, bool $refreshDomains): array
    {
        $where = '(domain IS NULL OR domain = \'\' OR app_name IS NULL OR app_name = \'\')';
        if ($refreshDomains) {
            $where = '('.$where.' OR domain_source = \'dns_cache\')';
        }
        $params = [];

        if ($lastId !== null) {
            $where .= ' AND id < :lastId';
            $params['lastId'] = $lastId;
        }

        return $this->connection->fetchAllAssociative(
            'SELECT id, direction, src_ip, dst_ip, protocol, src_port, dst_port, domain, app_name, domain_source
             FROM network_flow
             WHERE '.$where.'
             ORDER BY id DESC
             LIMIT '.$limit,
            $params
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private function fetchDnsMatchesByIp(array $rows): array
    {
        $candidateIps = [];

        foreach ($rows as $row) {
            foreach ($this->candidateIpsForRow($row) as $candidateIp) {
                $candidateIps[$candidateIp] = $candidateIp;
            }
        }

        if ($candidateIps === []) {
            return [];
        }

        $dnsRows = $this->connection->fetchAllAssociative(
            'SELECT a.resolved_ip, a.domain
             FROM dns_cache_record a
             WHERE a.resolved_ip IN (:ips)
               AND a.record_type IN (\'A\', \'AAAA\')
               AND NOT EXISTS (
                   SELECT 1
                   FROM dns_cache_record newer
                   WHERE newer.resolved_ip = a.resolved_ip
                     AND newer.record_type IN (\'A\', \'AAAA\')
                     AND (
                         newer.last_seen_at > a.last_seen_at
                         OR (newer.last_seen_at = a.last_seen_at AND newer.id > a.id)
                     )
               )',
            ['ips' => array_values($candidateIps)],
            ['ips' => ArrayParameterType::STRING]
        );

        $aliasesByCname = $this->fetchPreferredAliasesByCname($dnsRows);
        $dnsByIp = [];
        foreach ($dnsRows as $dnsRow) {
            $ip = isset($dnsRow['resolved_ip']) ? (string) $dnsRow['resolved_ip'] : '';
            if ($ip === '' || isset($dnsByIp[$ip])) {
                continue;
            }

            $addressDomain = $this->normalizeDomain(isset($dnsRow['domain']) ? (string) $dnsRow['domain'] : null);
            $domain = $addressDomain !== null ? ($aliasesByCname[$addressDomain] ?? $addressDomain) : null;
            if ($domain === null) {
                continue;
            }

            $dnsByIp[$ip] = $domain;
        }

        return $dnsByIp;
    }

    /**
     * @param list<array<string, mixed>> $dnsRows
     *
     * @return array<string, string>
     */
    private function fetchPreferredAliasesByCname(array $dnsRows): array
    {
        $cnames = [];
        foreach ($dnsRows as $dnsRow) {
            $domain = $this->normalizeDomain(isset($dnsRow['domain']) ? (string) $dnsRow['domain'] : null);
            if ($domain !== null) {
                $cnames[$domain] = $domain;
            }
        }

        if ($cnames === []) {
            return [];
        }

        $aliasRows = $this->connection->fetchAllAssociative(
            'SELECT c.cname, c.domain
             FROM dns_cache_record c
             WHERE c.cname IN (:cnames)
               AND c.record_type = \'CNAME\'
               AND NOT EXISTS (
                   SELECT 1
                   FROM dns_cache_record newer
                   WHERE newer.cname = c.cname
                     AND newer.record_type = \'CNAME\'
                     AND (
                         newer.last_seen_at > c.last_seen_at
                         OR (newer.last_seen_at = c.last_seen_at AND newer.id > c.id)
                     )
               )',
            ['cnames' => array_values($cnames)],
            ['cnames' => ArrayParameterType::STRING]
        );

        $aliasesByCname = [];
        foreach ($aliasRows as $aliasRow) {
            $cname = $this->normalizeDomain(isset($aliasRow['cname']) ? (string) $aliasRow['cname'] : null);
            $domain = $this->normalizeDomain(isset($aliasRow['domain']) ? (string) $aliasRow['domain'] : null);
            if ($cname !== null && $domain !== null) {
                $aliasesByCname[$cname] = $domain;
            }
        }

        return $aliasesByCname;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function candidateIpsForRow(array $row): array
    {
        return $this->networkFlowEnricher->remoteIpCandidates(
            (string) ($row['direction'] ?? 'external'),
            isset($row['src_ip']) ? (string) $row['src_ip'] : null,
            isset($row['dst_ip']) ? (string) $row['dst_ip'] : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $dnsByIp
     */
    private function domainForFlow(array $row, array $dnsByIp): ?string
    {
        foreach ($this->candidateIpsForRow($row) as $candidateIp) {
            if (isset($dnsByIp[$candidateIp])) {
                return $dnsByIp[$candidateIp];
            }
        }

        return null;
    }

    private function normalizeDomain(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '.');

        return $domain === '' || filter_var($domain, FILTER_VALIDATE_IP) ? null : $domain;
    }
}
