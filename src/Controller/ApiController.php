<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        return $this->json([
            'clients' => $this->em->getRepository(Client::class)->count([]),
            'devices' => $this->em->getRepository(Device::class)->count([]),
            'unlinkedDevices' => (int) $this->em->createQuery('SELECT COUNT(d.id) FROM App\Entity\Device d WHERE d.client IS NULL')->getSingleScalarResult(),
        ]);
    }

    #[Route('/clients', methods: ['GET'])]
    public function clients(): JsonResponse
    {
        return $this->json(array_map(fn (Client $client) => $this->clientPayload($client), $this->em->getRepository(Client::class)->findAll()));
    }

    #[Route('/clients/{id}', methods: ['GET'])]
    public function client(Client $client): JsonResponse
    {
        return $this->json($this->clientPayload($client));
    }

    #[Route('/devices', methods: ['GET'])]
    public function devices(): JsonResponse
    {
        return $this->json(array_map(fn (Device $device) => $this->devicePayload($device), $this->em->getRepository(Device::class)->findAll()));
    }

    #[Route('/devices/{id}', methods: ['GET'])]
    public function device(Device $device): JsonResponse
    {
        return $this->json($this->devicePayload($device));
    }

    #[Route('/devices/{id}/link-client', methods: ['POST'])]
    public function linkClient(Device $device, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: $request->request->all();
        $client = isset($data['clientId']) ? $this->em->getRepository(Client::class)->find((int) $data['clientId']) : null;
        if (!$client) {
            $client = (new Client())
                ->setFullName($data['fullName'] ?? null)
                ->setRoomNumber($data['roomNumber'] ?? null)
                ->setPhone($data['phone'] ?? null)
                ->setComment($data['comment'] ?? null);
            $this->em->persist($client);
        }
        $device->setClient($client);
        $this->em->flush();

        return $this->json($this->devicePayload($device));
    }

    #[Route('/reports/daily', methods: ['GET'])]
    public function daily(): JsonResponse
    {
        return $this->report(new \DateTimeImmutable('today'));
    }

    #[Route('/reports/monthly', methods: ['GET'])]
    public function monthly(): JsonResponse
    {
        return $this->report(new \DateTimeImmutable('-30 days'));
    }

    private function report(\DateTimeImmutable $from): JsonResponse
    {
        $rows = $this->em->createQuery('SELECT IDENTITY(u.device) deviceId, SUM(u.totalBytes) totalBytes FROM App\Entity\DeviceDailyUsage u WHERE u.date >= :from GROUP BY u.device ORDER BY totalBytes DESC')
            ->setParameter('from', $from)
            ->getArrayResult();
        return $this->json($rows);
    }

    private function clientPayload(Client $client): array
    {
        return [
            'id' => $client->getId(),
            'fullName' => $client->getFullName(),
            'roomNumber' => $client->getRoomNumber(),
            'phone' => $client->getPhone(),
            'status' => $client->getStatus(),
            'devices' => array_map(fn (Device $device) => $device->getId(), $client->getDevices()->toArray()),
        ];
    }

    private function devicePayload(Device $device): array
    {
        return [
            'id' => $device->getId(),
            'mac' => $device->getMac(),
            'currentIp' => $device->getCurrentIp(),
            'hostname' => $device->getHostname(),
            'vendor' => $device->getVendor(),
            'vlan' => $device->getVlan(),
            'clientId' => $device->getClient()?->getId(),
            'lastSeenAt' => $device->getLastSeenAt()?->format(DATE_ATOM),
        ];
    }
}
