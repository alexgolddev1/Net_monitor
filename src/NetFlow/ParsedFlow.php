<?php

namespace App\NetFlow;

use DateTimeImmutable;

class ParsedFlow
{
    /**
     * @param array<string, mixed> $rawFields
     */
    public function __construct(
        public readonly string $exporterIp,
        public readonly int $sourceId,
        public readonly ?string $srcIPv4,
        public readonly ?string $dstIPv4,
        public readonly ?int $bytes,
        public readonly ?int $packets,
        public readonly ?int $protocol,
        public readonly ?int $srcPort,
        public readonly ?int $dstPort,
        public readonly ?int $inputInterface,
        public readonly ?int $outputInterface,
        public readonly ?int $tcpFlags,
        public readonly ?DateTimeImmutable $firstSeen,
        public readonly ?DateTimeImmutable $lastSeen,
        public readonly array $rawFields,
    ) {
    }
}
