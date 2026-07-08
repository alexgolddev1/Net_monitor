<?php

namespace App\Service\IpIntel;

interface IpIntelProviderInterface
{
    public function analyze(string $ip): IpIntelResult;
}
