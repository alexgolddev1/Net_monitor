<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['received_at'], name: 'idx_network_flow_received_at')]
#[ORM\Index(columns: ['first_seen_at'], name: 'idx_network_flow_first_seen_at')]
#[ORM\Index(columns: ['device_id', 'received_at'], name: 'idx_network_flow_device_received')]
#[ORM\Index(columns: ['client_id', 'received_at'], name: 'idx_network_flow_client_received')]
#[ORM\Index(columns: ['src_ip'], name: 'idx_network_flow_src_ip')]
#[ORM\Index(columns: ['dst_ip'], name: 'idx_network_flow_dst_ip')]
#[ORM\Index(columns: ['direction'], name: 'idx_network_flow_direction')]
class NetworkFlow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 45)]
    private string $exporterIp;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $srcIp = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $dstIp = null;

    #[ORM\Column(nullable: true)]
    private ?int $srcPort = null;

    #[ORM\Column(nullable: true)]
    private ?int $dstPort = null;

    #[ORM\Column(nullable: true)]
    private ?int $protocol = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private int|string|null $bytes = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private int|string|null $packets = null;

    #[ORM\Column(nullable: true)]
    private ?int $inputInterface = null;

    #[ORM\Column(nullable: true)]
    private ?int $outputInterface = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Device $device = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Client $client = null;

    #[ORM\Column(length: 16)]
    private string $direction;

    public function getId(): ?int { return $this->id; }
    public function getExporterIp(): string { return $this->exporterIp; }
    public function setExporterIp(string $exporterIp): self { $this->exporterIp = $exporterIp; return $this; }
    public function getSrcIp(): ?string { return $this->srcIp; }
    public function setSrcIp(?string $srcIp): self { $this->srcIp = $srcIp; return $this; }
    public function getDstIp(): ?string { return $this->dstIp; }
    public function setDstIp(?string $dstIp): self { $this->dstIp = $dstIp; return $this; }
    public function getSrcPort(): ?int { return $this->srcPort; }
    public function setSrcPort(?int $srcPort): self { $this->srcPort = $srcPort; return $this; }
    public function getDstPort(): ?int { return $this->dstPort; }
    public function setDstPort(?int $dstPort): self { $this->dstPort = $dstPort; return $this; }
    public function getProtocol(): ?int { return $this->protocol; }
    public function setProtocol(?int $protocol): self { $this->protocol = $protocol; return $this; }
    public function getBytes(): ?int { return $this->bytes === null ? null : (int) $this->bytes; }
    public function setBytes(int|string|null $bytes): self { $this->bytes = $bytes; return $this; }
    public function getPackets(): ?int { return $this->packets === null ? null : (int) $this->packets; }
    public function setPackets(int|string|null $packets): self { $this->packets = $packets; return $this; }
    public function getInputInterface(): ?int { return $this->inputInterface; }
    public function setInputInterface(?int $inputInterface): self { $this->inputInterface = $inputInterface; return $this; }
    public function getOutputInterface(): ?int { return $this->outputInterface; }
    public function setOutputInterface(?int $outputInterface): self { $this->outputInterface = $outputInterface; return $this; }
    public function getFirstSeenAt(): ?\DateTimeImmutable { return $this->firstSeenAt; }
    public function setFirstSeenAt(?\DateTimeImmutable $firstSeenAt): self { $this->firstSeenAt = $firstSeenAt; return $this; }
    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): self { $this->lastSeenAt = $lastSeenAt; return $this; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(\DateTimeImmutable $receivedAt): self { $this->receivedAt = $receivedAt; return $this; }
    public function getDevice(): ?Device { return $this->device; }
    public function setDevice(?Device $device): self { $this->device = $device; return $this; }
    public function getClient(): ?Client { return $this->client; }
    public function setClient(?Client $client): self { $this->client = $client; return $this; }
    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $direction): self { $this->direction = $direction; return $this; }
}
