<?php

namespace App\Tests\NetFlow;

use App\NetFlow\NetFlowV9Parser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NetFlowV9ParserTest extends TestCase
{
    public function testParseInvalidVersionThrowsException(): void
    {
        $parser = new NetFlowV9Parser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported NetFlow version 5');

        $parser->parse($this->header(version: 5), '192.168.0.1');
    }

    public function testParseShortPacketReturnsWarning(): void
    {
        $parser = new NetFlowV9Parser();

        self::assertSame([], $parser->parse("\x00\x09", '192.168.0.1'));
        self::assertSame(['Packet too short: 2 bytes'], $parser->lastWarnings());
    }

    public function testParseTemplatePacketStoresTemplateWithoutFlows(): void
    {
        $parser = new NetFlowV9Parser();
        $packet = $this->header() . $this->templateFlowSet();

        self::assertSame([], $parser->parse($packet, '192.168.0.1'));
        self::assertSame(1, $parser->parsedTemplatesInLastPacket());
        self::assertSame([], $parser->lastWarnings());
    }

    public function testParseDataPacketWithKnownTemplate(): void
    {
        $parser = new NetFlowV9Parser();
        $parser->parse($this->header() . $this->templateFlowSet(), '192.168.0.1');

        $flows = $parser->parse($this->header() . $this->dataFlowSet(), '192.168.0.1');

        self::assertCount(1, $flows);
        self::assertSame('192.168.0.43', $flows[0]->srcIPv4);
        self::assertSame('142.250.74.142', $flows[0]->dstIPv4);
        self::assertSame('31.42.166.5', $flows[0]->postNatSrcIPv4);
        self::assertSame('142.250.74.142', $flows[0]->postNatDstIPv4);
        self::assertSame(123456, $flows[0]->bytes);
        self::assertSame(100, $flows[0]->packets);
        self::assertSame(6, $flows[0]->protocol);
        self::assertSame(54321, $flows[0]->srcPort);
        self::assertSame(443, $flows[0]->dstPort);
        self::assertNotNull($flows[0]->firstSeen);
        self::assertNotNull($flows[0]->lastSeen);
    }

    private function header(int $version = 9): string
    {
        return pack('nnNNNN', $version, 1, 100000, 1700000000, 1, 123);
    }

    private function templateFlowSet(): string
    {
        $template = pack('nn', 256, 11)
            . pack('nn', 8, 4)
            . pack('nn', 12, 4)
            . pack('nn', 225, 4)
            . pack('nn', 226, 4)
            . pack('nn', 1, 4)
            . pack('nn', 2, 4)
            . pack('nn', 4, 1)
            . pack('nn', 7, 2)
            . pack('nn', 11, 2)
            . pack('nn', 22, 4)
            . pack('nn', 21, 4);

        return pack('nn', 0, strlen($template) + 4) . $template;
    }

    private function dataFlowSet(): string
    {
        $record = inet_pton('192.168.0.43')
            . inet_pton('142.250.74.142')
            . inet_pton('31.42.166.5')
            . inet_pton('142.250.74.142')
            . pack('N', 123456)
            . pack('N', 100)
            . pack('C', 6)
            . pack('n', 54321)
            . pack('n', 443)
            . pack('N', 90000)
            . pack('N', 99000);

        return pack('nn', 256, strlen($record) + 4) . $record;
    }
}
