<?php

namespace App\Entity;

use App\Repository\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_device_mac', columns: ['mac'])]
class Device
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'devices')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Client $client = null;

    #[ORM\Column(length: 17)]
    private string $mac;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $currentIp = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $hostname = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $vendor = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $vlan = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $deviceName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'device', targetEntity: DeviceIpHistory::class)]
    private Collection $ipHistory;

    public function __construct()
    {
        $this->ipHistory = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->firstSeenAt ??= $now;
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Client { return $this->client; }
    public function setClient(?Client $client): self { $this->client = $client; return $this; }
    public function getMac(): string { return $this->mac; }
    public function setMac(string $mac): self { $this->mac = strtoupper($mac); return $this; }
    public function getCurrentIp(): ?string { return $this->currentIp; }
    public function setCurrentIp(?string $currentIp): self { $this->currentIp = $currentIp; return $this; }
    public function getHostname(): ?string { return $this->hostname; }
    public function setHostname(?string $hostname): self { $this->hostname = $hostname; return $this; }
    public function getVendor(): ?string { return $this->vendor; }
    public function setVendor(?string $vendor): self { $this->vendor = $vendor; return $this; }
    public function getVlan(): ?string { return $this->vlan; }
    public function setVlan(?string $vlan): self { $this->vlan = $vlan; return $this; }
    public function getDeviceName(): ?string { return $this->deviceName; }
    public function setDeviceName(?string $deviceName): self { $this->deviceName = $deviceName; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self
    {
        $comment = $comment !== null ? trim($comment) : null;
        $this->comment = $comment === '' ? null : $comment;

        return $this;
    }
    public function getFirstSeenAt(): ?\DateTimeImmutable { return $this->firstSeenAt; }
    public function setFirstSeenAt(?\DateTimeImmutable $firstSeenAt): self { $this->firstSeenAt = $firstSeenAt; return $this; }
    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): self { $this->lastSeenAt = $lastSeenAt; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getIpHistory(): Collection { return $this->ipHistory; }
}
