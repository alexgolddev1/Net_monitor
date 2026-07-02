<?php

namespace App\Tests\Command;

use App\Command\EnrichNetworkFlowsCommand;
use App\Service\AppClassifier;
use App\Service\NetworkFlowEnricher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class EnrichNetworkFlowsCommandTest extends TestCase
{
    public function testDomainMatchesByDestinationIp(): void
    {
        $command = $this->command();

        self::assertSame('poll1.fwdcdn.com', $this->domainForFlow($command, [
            'direction' => 'upload',
            'src_ip' => '192.168.0.85',
            'dst_ip' => '212.42.75.248',
        ], ['212.42.75.248' => 'poll1.fwdcdn.com']));
    }

    public function testDomainMatchesBySourceIp(): void
    {
        $command = $this->command();

        self::assertSame('poll1.fwdcdn.com', $this->domainForFlow($command, [
            'direction' => 'download',
            'src_ip' => '212.42.75.248',
            'dst_ip' => '192.168.0.85',
        ], ['212.42.75.248' => 'poll1.fwdcdn.com']));
    }

    public function testNoDomainWhenNoDnsMatch(): void
    {
        $command = $this->command();

        self::assertNull($this->domainForFlow($command, [
            'direction' => 'upload',
            'src_ip' => '192.168.0.85',
            'dst_ip' => '212.42.75.248',
        ], []));
    }

    public function testOwnWanIpMatchIsIgnored(): void
    {
        $command = $this->command();

        self::assertSame('poll1.fwdcdn.com', $this->domainForFlow($command, [
            'direction' => 'external',
            'src_ip' => '212.42.75.248',
            'dst_ip' => '31.42.166.5',
        ], [
            '31.42.166.5' => 'kpdi.pp.ua',
            '212.42.75.248' => 'poll1.fwdcdn.com',
        ]));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $dnsByIp
     */
    private function domainForFlow(EnrichNetworkFlowsCommand $command, array $row, array $dnsByIp): ?string
    {
        $method = new \ReflectionMethod($command, 'domainForFlow');
        $method->setAccessible(true);

        return $method->invoke($command, $row, $dnsByIp);
    }

    private function command(): EnrichNetworkFlowsCommand
    {
        $enricher = new NetworkFlowEnricher(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AppClassifier::class),
            '192.168.0.0/16',
            '31.42.166.5'
        );

        return new EnrichNetworkFlowsCommand(
            $this->createMock(Connection::class),
            $enricher,
            $this->createMock(AppClassifier::class),
            $this->createMock(EntityManagerInterface::class)
        );
    }
}
