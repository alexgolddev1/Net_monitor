<?php

namespace App\Tests\Service;

use App\Service\AppClassifier;
use App\Service\NetworkFlowEnricher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NetworkFlowEnricherTest extends TestCase
{
    public function testUploadPrefersDestinationIp(): void
    {
        $enricher = $this->enricher();

        self::assertSame(['212.42.75.248'], $enricher->remoteIpCandidates('upload', '192.168.0.85', '212.42.75.248'));
    }

    public function testDownloadPrefersSourceIp(): void
    {
        $enricher = $this->enricher();

        self::assertSame(['212.42.75.248'], $enricher->remoteIpCandidates('download', '212.42.75.248', '192.168.0.85'));
    }

    public function testExternalChecksBothSides(): void
    {
        $enricher = $this->enricher();

        self::assertSame(['198.51.100.20', '203.0.113.10'], $enricher->remoteIpCandidates('external', '203.0.113.10', '198.51.100.20'));
    }

    public function testLocalIpsAreIgnored(): void
    {
        $enricher = $this->enricher();

        self::assertSame([], $enricher->remoteIpCandidates('local', '192.168.0.85', '192.168.1.20'));
    }

    public function testOwnWanIpIsIgnored(): void
    {
        $enricher = $this->enricher();

        self::assertSame(['212.42.75.248'], $enricher->remoteIpCandidates('external', '212.42.75.248', '31.42.166.5'));
    }

    private function enricher(): NetworkFlowEnricher
    {
        return new NetworkFlowEnricher(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AppClassifier::class),
            '192.168.0.0/16',
            '31.42.166.5'
        );
    }
}
