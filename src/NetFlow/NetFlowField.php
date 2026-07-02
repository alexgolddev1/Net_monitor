<?php

namespace App\NetFlow;

class NetFlowField
{
    public const IN_BYTES = 1;
    public const IN_PKTS = 2;
    public const PROTOCOL = 4;
    public const TCP_FLAGS = 6;
    public const L4_SRC_PORT = 7;
    public const IPV4_SRC_ADDR = 8;
    public const INPUT_SNMP = 10;
    public const L4_DST_PORT = 11;
    public const IPV4_DST_ADDR = 12;
    public const OUTPUT_SNMP = 14;
    public const LAST_SWITCHED = 21;
    public const FIRST_SWITCHED = 22;

    public const ALIASES = [
        self::IN_BYTES => 'bytes',
        self::IN_PKTS => 'packets',
        self::PROTOCOL => 'protocol',
        self::TCP_FLAGS => 'tcpFlags',
        self::L4_SRC_PORT => 'srcPort',
        self::IPV4_SRC_ADDR => 'srcIPv4',
        self::INPUT_SNMP => 'inputInterface',
        self::L4_DST_PORT => 'dstPort',
        self::IPV4_DST_ADDR => 'dstIPv4',
        self::OUTPUT_SNMP => 'outputInterface',
        self::LAST_SWITCHED => 'lastSeen',
        self::FIRST_SWITCHED => 'firstSeen',
    ];

    public function __construct(
        public readonly int $type,
        public readonly int $length,
    ) {
    }

    public function alias(): string
    {
        return self::ALIASES[$this->type] ?? sprintf('field_%d', $this->type);
    }
}
