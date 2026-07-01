<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['ip'], name: 'idx_ip_history_ip')]
class DeviceIpHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ipHistory')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function getId(): ?int { return $this->id; }
    public function getDevice(): Device { return $this->device; }
    public function setDevice(Device $device): self { $this->device = $device; return $this; }
    public function getIp(): string { return $this->ip; }
    public function setIp(string $ip): self { $this->ip = $ip; return $this; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function setFirstSeenAt(\DateTimeImmutable $firstSeenAt): self { $this->firstSeenAt = $firstSeenAt; return $this; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): self { $this->lastSeenAt = $lastSeenAt; return $this; }
}
