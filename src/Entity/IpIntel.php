<?php

namespace App\Entity;

use App\Repository\IpIntelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IpIntelRepository::class)]
#[ORM\Table(name: 'ip_intel')]
#[ORM\UniqueConstraint(name: 'uniq_ip_intel_ip', columns: ['ip'])]
#[ORM\Index(columns: ['checked_at'], name: 'idx_ip_intel_checked_at')]
#[ORM\Index(columns: ['category'], name: 'idx_ip_intel_category')]
#[ORM\HasLifecycleCallbacks]
class IpIntel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(nullable: true)]
    private ?int $asn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $organization = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $isp = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reverseDns = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isHosting = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isProxy = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isMobile = null;

    #[ORM\Column(nullable: true)]
    private ?int $abuseScore = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(nullable: true)]
    private ?int $confidence = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function setIp(string $ip): self
    {
        $this->ip = trim($ip);

        return $this;
    }

    public function getAsn(): ?int
    {
        return $this->asn;
    }

    public function setAsn(?int $asn): self
    {
        $this->asn = $asn;

        return $this;
    }

    public function getOrganization(): ?string
    {
        return $this->organization;
    }

    public function setOrganization(?string $organization): self
    {
        $organization = $organization !== null ? trim($organization) : null;
        $this->organization = $organization === '' ? null : $organization;

        return $this;
    }

    public function getIsp(): ?string
    {
        return $this->isp;
    }

    public function setIsp(?string $isp): self
    {
        $isp = $isp !== null ? trim($isp) : null;
        $this->isp = $isp === '' ? null : $isp;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $country = $country !== null ? trim($country) : null;
        $this->country = $country === '' ? null : $country;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $city = $city !== null ? trim($city) : null;
        $this->city = $city === '' ? null : $city;

        return $this;
    }

    public function getReverseDns(): ?string
    {
        return $this->reverseDns;
    }

    public function setReverseDns(?string $reverseDns): self
    {
        $reverseDns = $reverseDns !== null ? trim($reverseDns) : null;
        $this->reverseDns = $reverseDns === '' ? null : $reverseDns;

        return $this;
    }

    public function getIsHosting(): ?bool
    {
        return $this->isHosting;
    }

    public function setIsHosting(?bool $isHosting): self
    {
        $this->isHosting = $isHosting;

        return $this;
    }

    public function getIsProxy(): ?bool
    {
        return $this->isProxy;
    }

    public function setIsProxy(?bool $isProxy): self
    {
        $this->isProxy = $isProxy;

        return $this;
    }

    public function getIsMobile(): ?bool
    {
        return $this->isMobile;
    }

    public function setIsMobile(?bool $isMobile): self
    {
        $this->isMobile = $isMobile;

        return $this;
    }

    public function getAbuseScore(): ?int
    {
        return $this->abuseScore;
    }

    public function setAbuseScore(?int $abuseScore): self
    {
        $this->abuseScore = $abuseScore;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $category = $category !== null ? trim($category) : null;
        $this->category = $category === '' ? null : $category;

        return $this;
    }

    public function getConfidence(): ?int
    {
        return $this->confidence;
    }

    public function setConfidence(?int $confidence): self
    {
        $this->confidence = $confidence;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $source = $source !== null ? trim($source) : null;
        $this->source = $source === '' ? null : $source;

        return $this;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(?\DateTimeImmutable $checkedAt): self
    {
        $this->checkedAt = $checkedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
