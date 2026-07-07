<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Device;
use App\Entity\DeviceProfileChangeRequest;
use App\Repository\DeviceRepository;
use App\Service\PageCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeviceRepository $deviceRepository,
        private readonly PageCacheService $pageCache,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $identifier = trim((string) $request->query->get('identifier', $request->request->get('identifier', '')));
        $identifier = $identifier !== ''
            ? $identifier
            : trim((string) ($request->query->get('ip', $request->query->get('mac', ''))));
        $profileRequestsReady = $this->hasDeviceProfileRequestsTable();
        if ($identifier === '') {
            $clientIp = $request->getClientIp();
            if ($clientIp !== null) {
                $matched = $this->deviceRepository->findOneByIdentifier($clientIp);
                if ($matched) {
                    $identifier = $clientIp;
                }
            }
        }
        $device = $identifier !== '' ? $this->deviceRepository->findOneByIdentifier($identifier) : null;
        $message = null;

        if ($identifier !== '' && $device === null && !$request->isMethod('POST')) {
            $message = 'Пристрій не знайдено.';
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('public_device_profile_request', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $deviceId = (int) $request->request->get('device_id');
            $device = $deviceId > 0 ? $this->em->getRepository(Device::class)->find($deviceId) : $device;

            if (!$profileRequestsReady) {
                $message = 'Запити змін ще не активовані. Потрібно застосувати міграцію бази даних.';
            } elseif (!$device) {
                $message = 'Пристрій не знайдено.';
            } else {
                $changeRequest = (new DeviceProfileChangeRequest())
                    ->setDevice($device)
                    ->setFullName($this->normalizedInput($request->request->get('full_name')))
                    ->setRoomNumber($this->normalizedInput($request->request->get('room_number')))
                    ->setPhone($this->normalizedInput($request->request->get('phone')))
                    ->setComment($this->normalizedInput($request->request->get('comment')))
                    ->setRequesterIp($request->getClientIp())
                    ->setStatus('pending');

                $this->em->persist($changeRequest);
                $this->em->flush();

                $message = 'Зміни збережено як запит. Вони будуть застосовані після підтвердження адміністратором.';
            }
        }

        $devicePayload = null;
        $deviceDetail = null;
        $pendingRequests = [];
        $client = null;

        if ($device) {
            $client = $device->getClient();
            $devicePayload = [
                'id' => $device->getId(),
                'mac' => $device->getMac(),
                'currentIp' => $device->getCurrentIp(),
                'hostname' => $device->getHostname(),
                'comment' => $device->getComment(),
                'client' => $client ? [
                    'id' => $client->getId(),
                    'fullName' => $client->getFullName(),
                    'roomNumber' => $client->getRoomNumber(),
                    'phone' => $client->getPhone(),
                    'comment' => $client->getComment(),
                    'displayName' => $client->getDisplayName(),
                ] : null,
            ];

            $deviceDetail = $this->pageCache->cachedDeviceDetail((int) $device->getId());
            if ($profileRequestsReady) {
                $pendingRequests = $this->em->getRepository(DeviceProfileChangeRequest::class)->findBy(
                    ['device' => $device, 'status' => 'pending'],
                    ['createdAt' => 'DESC']
                );
            }
        }

        return $this->render('public/index.html.twig', [
            'identifier' => $identifier,
            'device' => $devicePayload,
            'deviceDetail' => $deviceDetail,
            'pendingRequests' => $pendingRequests,
            'message' => $message,
            'profileRequestsReady' => $profileRequestsReady,
        ]);
    }

    private function normalizedInput(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private function hasDeviceProfileRequestsTable(): bool
    {
        $tables = $this->em->getConnection()->createSchemaManager()->listTableNames();

        return in_array('device_profile_change_request', $tables, true);
    }
}
