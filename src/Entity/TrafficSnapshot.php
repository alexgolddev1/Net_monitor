<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['ip'], name: 'idx_snapshot_ip')]
#[ORM\Index(columns: ['snapshot_at'], name: 'idx_snapshot_at')]
class TrafficSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Device $device = null;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $mac = null;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(type: Types::BIGINT)]
    private int|string $bytesIn = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int|string $bytesOut = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int|string $totalBytes = 0;

    #[ORM\Column(nullable: true)]
    private ?int $packetsIn = null;

    #[ORM\Column(nullable: true)]
    private ?int $packetsOut = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $appsJson = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $destinationsJson = null;

    #[ORM\Column]
    private \DateTimeImmutable $snapshotAt;

    public function getId(): ?int { return $this->id; }
    public function getDevice(): ?Device { return $this->device; }
    public function setDevice(?Device $device): self { $this->device = $device; return $this; }
    public function getMac(): ?string { return $this->mac; }
    public function setMac(?string $mac): self { $this->mac = $mac ? strtoupper($mac) : null; return $this; }
    public function getIp(): string { return $this->ip; }
    public function setIp(string $ip): self { $this->ip = $ip; return $this; }
    public function getBytesIn(): int { return (int) $this->bytesIn; }
    public function setBytesIn(int $bytesIn): self { $this->bytesIn = $bytesIn; return $this; }
    public function getBytesOut(): int { return (int) $this->bytesOut; }
    public function setBytesOut(int $bytesOut): self { $this->bytesOut = $bytesOut; return $this; }
    public function getTotalBytes(): int { return (int) $this->totalBytes; }
    public function setTotalBytes(int $totalBytes): self { $this->totalBytes = $totalBytes; return $this; }
    public function getPacketsIn(): ?int { return $this->packetsIn; }
    public function setPacketsIn(?int $packetsIn): self { $this->packetsIn = $packetsIn; return $this; }
    public function getPacketsOut(): ?int { return $this->packetsOut; }
    public function setPacketsOut(?int $packetsOut): self { $this->packetsOut = $packetsOut; return $this; }
    public function getAppsJson(): ?array { return $this->appsJson; }
    public function setAppsJson(?array $appsJson): self { $this->appsJson = $appsJson; return $this; }
    public function getDestinationsJson(): ?array { return $this->destinationsJson; }
    public function setDestinationsJson(?array $destinationsJson): self { $this->destinationsJson = $destinationsJson; return $this; }
    public function getSnapshotAt(): \DateTimeImmutable { return $this->snapshotAt; }
    public function setSnapshotAt(\DateTimeImmutable $snapshotAt): self { $this->snapshotAt = $snapshotAt; return $this; }
}
