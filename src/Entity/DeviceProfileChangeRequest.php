<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class DeviceProfileChangeRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $roomNumber = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 16)]
    private string $status = 'pending';

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $requesterIp = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewNote = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDevice(): Device { return $this->device; }
    public function setDevice(Device $device): self { $this->device = $device; return $this; }
    public function getFullName(): ?string { return $this->fullName; }
    public function setFullName(?string $fullName): self { $this->fullName = $fullName; return $this; }
    public function getRoomNumber(): ?string { return $this->roomNumber; }
    public function setRoomNumber(?string $roomNumber): self { $this->roomNumber = $roomNumber; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self { $this->comment = $comment; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getRequesterIp(): ?string { return $this->requesterIp; }
    public function setRequesterIp(?string $requesterIp): self { $this->requesterIp = $requesterIp; return $this; }
    public function getReviewNote(): ?string { return $this->reviewNote; }
    public function setReviewNote(?string $reviewNote): self { $this->reviewNote = $reviewNote; return $this; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): self { $this->reviewedAt = $reviewedAt; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
