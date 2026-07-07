<?php

namespace App\Twig;

use App\Entity\DeviceProfileChangeRequest;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class DeviceProfileRequestExtension extends AbstractExtension
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_device_profile_requests_count', [$this, 'pendingCount']),
        ];
    }

    public function pendingCount(): int
    {
        if (!$this->hasDeviceProfileRequestsTable()) {
            return 0;
        }

        return (int) $this->em->getRepository(DeviceProfileChangeRequest::class)->count(['status' => 'pending']);
    }

    private function hasDeviceProfileRequestsTable(): bool
    {
        $tables = $this->em->getConnection()->createSchemaManager()->listTableNames();

        return in_array('device_profile_change_request', $tables, true);
    }
}
