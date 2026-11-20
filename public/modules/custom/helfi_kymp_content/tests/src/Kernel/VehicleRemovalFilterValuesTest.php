<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\helfi_api_base\ServiceMap\ResidentParkingZoneServiceInterface;
use Drupal\helfi_api_base\ServiceMap\ServiceMapInterface;
use Drupal\helfi_kymp_content\VehicleRemovalFilterValues;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests VehicleRemovalFilterValues.
 */
#[CoversClass(VehicleRemovalFilterValues::class)]
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class VehicleRemovalFilterValuesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_api_base',
    'helfi_kymp_content',
    'search_api',
    'user',
    'diff',
  ];

  /**
   * The Servicemap mock.
   */
  private ServiceMapInterface&MockObject $serviceMap;

  /**
   * The parking zone service mock.
   */
  private ResidentParkingZoneServiceInterface&MockObject $parkingZoneService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->serviceMap = $this->createMock(ServiceMapInterface::class);
    $this->parkingZoneService = $this->createMock(ResidentParkingZoneServiceInterface::class);
  }

  /**
   * Creates the service with the mocks.
   */
  private function createFilterValues(): VehicleRemovalFilterValues {
    return new VehicleRemovalFilterValues(
      $this->serviceMap,
      $this->parkingZoneService,
      $this->container->get('cache.default'),
      $this->container->get('logger.channel.helfi_kymp_content'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * Tests that values are unique, sorted and cached.
   */
  public function testValues(): void {
    $this->serviceMap->expects($this->once())->method('getPostalCodes')->willReturn(['00200', '00100', '00200']);
    $this->parkingZoneService->expects($this->once())
      ->method('getParkingZoneNames')
      ->willReturn(['Kamppi', 'Alppiharju', 'Kamppi']);

    for ($i = 0; $i < 2; $i++) {
      $filterValues = $this->createFilterValues();
      $this->assertSame(['00100', '00200'], $filterValues->getPostalCodes());
      $this->assertSame(['Alppiharju', 'Kamppi'], $filterValues->getParkingZoneNames());
    }
  }

  /**
   * Tests that the cached values expire.
   */
  public function testCacheExpiry(): void {
    $this->parkingZoneService->method('getParkingZoneNames')->willReturn(['Kamppi']);
    $this->createFilterValues()->getParkingZoneNames();

    $cached = $this->container->get('cache.default')->get('helfi_kymp_content:vehicle_removal_parking_zones');
    $this->assertNotFalse($cached);
    $this->assertSame($this->container->get('datetime.time')->getRequestTime() + 86400, $cached->expire);
  }

  /**
   * Tests that the zones are fetched without the postal codes.
   */
  public function testSeparateCache(): void {
    $this->serviceMap->expects($this->never())->method('getPostalCodes');
    $this->parkingZoneService->method('getParkingZoneNames')->willReturn(['Kamppi']);

    $this->assertSame(['Kamppi'], $this->createFilterValues()->getParkingZoneNames());
  }

  /**
   * Tests that a failed fetch is not cached.
   */
  public function testFailure(): void {
    $calls = 0;
    $this->parkingZoneService->expects($this->exactly(2))
      ->method('getParkingZoneNames')
      ->willReturnCallback(static function () use (&$calls): array {
        if ($calls++ === 0) {
          throw new RequestException('Service unavailable', new Request('GET', 'https://example.com'));
        }
        return ['Kamppi'];
      });

    $this->assertNull($this->createFilterValues()->getParkingZoneNames());
    $this->assertSame(['Kamppi'], $this->createFilterValues()->getParkingZoneNames());
  }

  /**
   * Tests that an empty list is not cached.
   */
  public function testEmpty(): void {
    $this->serviceMap->expects($this->exactly(2))
      ->method('getPostalCodes')
      ->willReturnOnConsecutiveCalls([], ['00100']);

    $this->assertNull($this->createFilterValues()->getPostalCodes());
    $this->assertSame(['00100'], $this->createFilterValues()->getPostalCodes());
  }

}
