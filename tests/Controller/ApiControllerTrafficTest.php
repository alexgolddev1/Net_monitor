<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ApiController;
use App\Service\DashboardCacheService;
use App\Service\MikroTikClient;
use App\Service\PageCacheService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

final class ApiControllerTrafficTest extends TestCase
{
    public function testDayRangeReadsOnlyHourlyRollups(): void
    {
        $cacheDir = sys_get_temp_dir().'/kpdi-traffic-cache-'.uniqid('', true);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())
            ->method('fetchOne');
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('FROM traffic_hourly_usage'),
                self::callback(static function (array $params): bool {
                    return isset($params['start'], $params['end'], $params['scopeType'], $params['scopeId'])
                        && $params['scopeType'] === 'all'
                        && $params['scopeId'] === 0;
                })
            )
            ->willReturn([
                ['bucket' => '2026-07-07 10:00:00', 'downloadBytes' => '1024', 'uploadBytes' => '2048'],
                ['bucket' => '2026-07-07 11:00:00', 'downloadBytes' => '0', 'uploadBytes' => '4096'],
            ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('getConnection')
            ->willReturn($connection);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($cacheDir);

        $controller = new ApiController(
            $em,
            $this->createMock(DashboardCacheService::class),
            $this->createMock(PageCacheService::class),
            $this->createMock(MikroTikClient::class),
            $kernel,
        );

        $response = $controller->trafficSeries(Request::create('/api/traffic', 'GET', ['range' => 'day']));
        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Останні 24 години', $payload['rangeLabel']);
        self::assertSame('hour', $payload['granularity']);
        self::assertCount(24, $payload['labels']);
        self::assertCount(24, $payload['buckets']);
        self::assertSame(1024, array_sum($payload['download']));
        self::assertSame(6144, array_sum($payload['upload']));
        self::assertSame(7168, $payload['total']);

        $secondResponse = $controller->trafficSeries(Request::create('/api/traffic', 'GET', ['range' => 'day']));
        self::assertSame(200, $secondResponse->getStatusCode());
        self::assertSame((string) $response->getContent(), (string) $secondResponse->getContent());
    }

    public function testTrafficBreakdownReturnsParticipants(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::stringContains('FROM network_flow'), self::callback(static function (array $params): bool {
                return isset($params['start'], $params['end']);
            }))
            ->willReturn([
                [
                    'deviceId' => 15,
                    'mac' => 'AA:BB:CC:DD:EE:FF',
                    'hostname' => 'desk-15',
                    'currentIp' => '10.0.0.15',
                    'clientId' => 4,
                    'clientFullName' => 'Ivan Petrenko',
                    'clientRoomNumber' => '402',
                    'downloadBytes' => '1024',
                    'uploadBytes' => '2048',
                ],
            ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('getConnection')
            ->willReturn($connection);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn(sys_get_temp_dir());

        $controller = new ApiController(
            $em,
            $this->createMock(DashboardCacheService::class),
            $this->createMock(PageCacheService::class),
            $this->createMock(MikroTikClient::class),
            $kernel,
        );

        $response = $controller->trafficBreakdown(Request::create('/api/traffic/breakdown', 'GET', [
            'range' => '12h',
            'bucket' => '2026-07-07T17:00:00+03:00',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hour', $payload['granularity']);
        self::assertSame('2026-07-07 17:00', $payload['bucketLabel']);
        self::assertCount(1, $payload['participants']);
        self::assertSame(3072, $payload['total']);
        self::assertSame('AA:BB:CC:DD:EE:FF', $payload['participants'][0]['mac']);
        self::assertSame(1024, $payload['participants'][0]['download']);
        self::assertSame(2048, $payload['participants'][0]['upload']);
    }
}
