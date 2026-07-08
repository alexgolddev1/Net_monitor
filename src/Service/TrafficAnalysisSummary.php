<?php

namespace App\Service;

final class TrafficAnalysisSummary
{
    public function __construct(
        public int $refreshedIps = 0,
        public int $torrent = 0,
        public int $vpn = 0,
        public int $hosting = 0,
        public int $google = 0,
        public int $meta = 0,
        public int $unknown = 0,
    ) {
    }
}
