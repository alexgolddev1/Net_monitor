<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_device_usage_date', columns: ['device_id', 'date'])]
class DeviceDailyUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::BIGINT)]
    private int|string $bytesIn = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int|string $bytesOut = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int|string $totalBytes = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $topAppsJson = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $topDestinationsJson = null;

    public function getId(): ?int { return $this->id; }
    public function getDevice(): Device { return $this->device; }
    public function setDevice(Device $device): self { $this->device = $device; return $this; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): self { $this->date = $date; return $this; }
    public function getBytesIn(): int { return (int) $this->bytesIn; }
    public function setBytesIn(int $bytesIn): self { $this->bytesIn = $bytesIn; return $this; }
    public function getBytesOut(): int { return (int) $this->bytesOut; }
    public function setBytesOut(int $bytesOut): self { $this->bytesOut = $bytesOut; return $this; }
    public function getTotalBytes(): int { return (int) $this->totalBytes; }
    public function setTotalBytes(int $totalBytes): self { $this->totalBytes = $totalBytes; return $this; }
    public function getTopAppsJson(): ?array { return $this->topAppsJson; }
    public function setTopAppsJson(?array $topAppsJson): self { $this->topAppsJson = $topAppsJson; return $this; }
    public function getTopDestinationsJson(): ?array { return $this->topDestinationsJson; }
    public function setTopDestinationsJson(?array $topDestinationsJson): self { $this->topDestinationsJson = $topDestinationsJson; return $this; }
}
