<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\DeviceProfileChangeRequest;
use App\Service\DashboardCacheService;
use App\Service\PageCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/device-profile-requests')]
class AdminDeviceProfileRequestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PageCacheService $pageCache,
        private readonly DashboardCacheService $dashboardCache,
    ) {
    }

    #[Route('', name: 'admin_device_profile_requests', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/device_profile_requests.html.twig', [
            'pendingRequests' => $this->hasDeviceProfileRequestsTable()
                ? $this->em->getRepository(DeviceProfileChangeRequest::class)->findBy(
                    ['status' => 'pending'],
                    ['createdAt' => 'DESC']
                )
                : [],
            'profileRequestsReady' => $this->hasDeviceProfileRequestsTable(),
        ]);
    }

    #[Route('/{id}/approve', name: 'admin_device_profile_request_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(DeviceProfileChangeRequest $changeRequest, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('device_profile_request_'.$changeRequest->getId().'_approve', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($changeRequest->getStatus() !== 'pending') {
            $this->addFlash('warning', 'Запит уже оброблено.');
            return $this->redirectToRoute('admin_device_profile_requests');
        }

        $device = $changeRequest->getDevice();
        $client = $device->getClient();

        if (!$client) {
            $client = new Client();
            $this->em->persist($client);
            $device->setClient($client);
        }

        $client
            ->setFullName($changeRequest->getFullName())
            ->setRoomNumber($changeRequest->getRoomNumber())
            ->setPhone($changeRequest->getPhone())
            ->setComment($changeRequest->getComment());

        $changeRequest
            ->setStatus('approved')
            ->setReviewedAt(new \DateTimeImmutable())
            ->setReviewNote($this->normalizedInput($request->request->get('review_note')));

        $this->em->flush();
        $this->pageCache->refresh();
        $this->dashboardCache->refreshPayload();
        $this->addFlash('success', 'Зміни застосовано.');

        return $this->redirectToRoute('admin_device_profile_requests');
    }

    #[Route('/{id}/reject', name: 'admin_device_profile_request_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(DeviceProfileChangeRequest $changeRequest, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('device_profile_request_'.$changeRequest->getId().'_reject', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($changeRequest->getStatus() !== 'pending') {
            $this->addFlash('warning', 'Запит уже оброблено.');
            return $this->redirectToRoute('admin_device_profile_requests');
        }

        $changeRequest
            ->setStatus('rejected')
            ->setReviewedAt(new \DateTimeImmutable())
            ->setReviewNote($this->normalizedInput($request->request->get('review_note')));

        $this->em->flush();
        $this->addFlash('success', 'Зміни відхилено.');

        return $this->redirectToRoute('admin_device_profile_requests');
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
