<?php

namespace App\Tests\Service;

use App\Service\ClientResolver;
use App\Service\MikroTikClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MikroTikClientTest extends TestCase
{
    public function testParseRouterOsSentencePreservesDnsTypeField(): void
    {
        $client = new MikroTikClient(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ClientResolver::class),
            $this->createMock(LoggerInterface::class),
            false
        );

        $method = new \ReflectionMethod($client, 'parseRouterOsSentence');
        $method->setAccessible(true);

        $row = $method->invoke($client, [
            '!re',
            '=name=example.com',
            '=type=A',
            '=data=142.250.185.238',
            '=ttl=300',
        ]);

        self::assertSame('A', $row['type']);
        self::assertSame('!re', $row['_replyType']);
        self::assertSame('example.com', $row['name']);
        self::assertSame('142.250.185.238', $row['data']);
        self::assertSame('300', $row['ttl']);
    }
}
