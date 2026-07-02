<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['resolved_ip'], name: 'idx_dns_cache_record_resolved_ip')]
#[ORM\Index(columns: ['domain'], name: 'idx_dns_cache_record_domain')]
#[ORM\Index(columns: ['last_seen_at'], name: 'idx_dns_cache_record_last_seen_at')]
#[ORM\UniqueConstraint(name: 'uniq_dns_cache_record_signature', columns: ['domain', 'record_type', 'resolved_ip', 'cname'])]
class DnsCacheRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $domain;

    #[ORM\Column(length: 16)]
    private string $recordType;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $resolvedIp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cname = null;

    #[ORM\Column(nullable: true)]
    private ?int $ttl = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(length: 32, options: ['default' => 'mikrotik_cache'])]
    private string $source = 'mikrotik_cache';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): self
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === null) {
            throw new \InvalidArgumentException('DNS cache domain cannot be empty.');
        }

        $this->domain = $normalized;

        return $this;
    }

    public function getRecordType(): string
    {
        return $this->recordType;
    }

    public function setRecordType(string $recordType): self
    {
        $this->recordType = strtoupper(trim($recordType));

        return $this;
    }

    public function getResolvedIp(): ?string
    {
        return $this->resolvedIp;
    }

    public function setResolvedIp(?string $resolvedIp): self
    {
        $this->resolvedIp = $resolvedIp !== null ? trim($resolvedIp) : null;

        return $this;
    }

    public function getCname(): ?string
    {
        return $this->cname;
    }

    public function setCname(?string $cname): self
    {
        $this->cname = $this->normalizeDomain($cname);

        return $this;
    }

    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    public function setTtl(?int $ttl): self
    {
        $this->ttl = $ttl !== null && $ttl >= 0 ? $ttl : null;

        return $this;
    }

    public function getFirstSeenAt(): ?\DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(?\DateTimeImmutable $firstSeenAt): self
    {
        $this->firstSeenAt = $firstSeenAt;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $source = trim($source);
        $this->source = $source === '' ? 'mikrotik_cache' : $source;

        return $this;
    }

    private function normalizeDomain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));
        $value = rtrim($value, '.');

        return $value === '' ? null : $value;
    }
}
