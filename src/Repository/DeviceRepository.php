<?php

namespace App\Repository;

use App\Entity\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    public function findOneByMac(string $mac): ?Device
    {
        return $this->findOneBy(['mac' => strtoupper($mac)]);
    }

    public function findOneByIdentifier(string $identifier): ?Device
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_IP)) {
            $device = $this->findOneBy(['currentIp' => $identifier]);
            if ($device) {
                return $device;
            }

            return $this->createQueryBuilder('d')
                ->innerJoin('d.ipHistory', 'h')
                ->andWhere('h.ip = :ip')
                ->setParameter('ip', $identifier)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        $mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', $identifier) ?: '');
        if (strlen($mac) === 12) {
            $mac = implode(':', str_split($mac, 2));
        } else {
            $mac = strtoupper(str_replace(['-', '.', ' '], ':', $identifier));
            $mac = preg_replace('/[^0-9A-F:]/i', '', $mac) ?: $mac;
            $mac = preg_replace('/:{2,}/', ':', $mac) ?: $mac;
        }

        return $this->findOneBy(['mac' => $mac]);
    }
}
