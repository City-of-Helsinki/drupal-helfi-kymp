<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\helfi_api_base\Environment\EnvironmentEnum;
use Drupal\helfi_api_base\Environment\EnvironmentResolverInterface;
use Drupal\helfi_api_base\Environment\Project;
use Drupal\helfi_api_base\ServiceMap\ResidentParkingZoneServiceInterface;
use Drupal\helfi_api_base\ServiceMap\ServiceMapInterface;
use Drupal\helfi_hakuvahti\DrupalSettings;
use Drupal\helfi_kymp_content\Plugin\Block\VehicleRemovalBlock;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\EnvironmentResolverTrait;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests VehicleRemovalBlock.
 */
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class VehicleRemovalBlockTest extends KernelTestBase {

  use EnvironmentResolverTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_api_base',
    'helfi_react_search',
    'helfi_kymp_content',
    'helfi_hakuvahti',
    'search_api',
    'system',
    'user',
    'diff',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['helfi_hakuvahti']);
    $this->setActiveProject(Project::LIIKENNE, EnvironmentEnum::Local);
  }

  /**
   * Creates the block with the given Servicemap results.
   *
   * @param string[]|\Throwable $postalCodes
   *   The postal codes or an exception to throw.
   * @param string[] $parkingZones
   *   The parking zone names.
   *
   * @return \Drupal\helfi_kymp_content\Plugin\Block\VehicleRemovalBlock
   *   The block.
   */
  private function createBlock(array|\Throwable $postalCodes = [], array $parkingZones = []): VehicleRemovalBlock {
    $serviceMap = $this->createMock(ServiceMapInterface::class);
    $parkingZoneService = $this->createMock(ResidentParkingZoneServiceInterface::class);

    if ($postalCodes instanceof \Throwable) {
      $serviceMap->method('getPostalCodes')->willThrowException($postalCodes);
    }
    else {
      $serviceMap->method('getPostalCodes')->willReturn($postalCodes);
    }
    $parkingZoneService->method('getParkingZoneNames')->willReturn($parkingZones);

    return new VehicleRemovalBlock(
      [],
      'kymp_vehicle_removal',
      ['provider' => 'helfi_kymp_content'],
      $this->container->get(DrupalSettings::class),
      $this->container->get('config.factory'),
      $this->container->get(EnvironmentResolverInterface::class),
      $serviceMap,
      $parkingZoneService,
      $this->container->get('cache.default'),
      $this->container->get('logger.channel.helfi_kymp_content'),
    );
  }

  /**
   * Tests the block build output.
   */
  public function testBuild(): void {
    $this->config('react_search.settings')
      ->set('sentry_dsn_react', 'https://sentry.example.com/123')
      ->save();

    $build = $this->createBlock()->build();

    $this->assertEquals('vehicle_removal', $build['#theme']);
    $this->assertContains('helfi_kymp_content/vehicle-removal-search', $build['#attached']['library']);
    $this->assertEquals('https://elastic-proxy-helfi-kymp.docker.so', $build['#attached']['drupalSettings']['helfi_react_search']['elastic_proxy_url']);
    $this->assertEquals('https://sentry.example.com/123', $build['#attached']['drupalSettings']['helfi_react_search']['sentry_dsn_react']);
    $this->assertContains('config:helfi_api_base.environment_resolver.settings', $build['#cache']['tags']);
    $this->assertContains('config:react_search.settings', $build['#cache']['tags']);
  }

  /**
   * Tests that filter values are fetched from Servicemap and cached.
   */
  public function testFilterValues(): void {
    $build = $this->createBlock(
      ['00200', '00100', '00200'],
      ['Kamppi', 'Alppiharju', 'Kamppi'],
    )->build();

    $settings = $build['#attached']['drupalSettings']['helfi_react_search'];
    $this->assertEquals(['00100', '00200'], $settings['postal_codes'], 'Unique postal codes are listed in order.');
    $this->assertEquals(['Alppiharju', 'Kamppi'], $settings['resident_parking_zones'], 'Unique zones are listed in order.');
    $this->assertNotEquals(0, $build['#cache']['max-age'], 'The block is cached.');

    $build = $this->createBlock(['00500'], ['Vallila'])->build();

    $settings = $build['#attached']['drupalSettings']['helfi_react_search'];
    $this->assertEquals(['00100', '00200'], $settings['postal_codes'], 'Cached filter values are used.');
    $this->assertEquals(['Alppiharju', 'Kamppi'], $settings['resident_parking_zones'], 'Cached filter values are used.');
  }

  /**
   * Tests that a failed fetch gives empty filter values that are not cached.
   */
  public function testFilterValuesFailure(): void {
    $build = $this->createBlock(
      new RequestException('Service unavailable', new Request('GET', 'https://example.com')),
    )->build();

    $settings = $build['#attached']['drupalSettings']['helfi_react_search'];
    $this->assertEquals([], $settings['postal_codes'], 'Filter values are empty.');
    $this->assertEquals([], $settings['resident_parking_zones'], 'Filter values are empty.');
    $this->assertEquals(0, $build['#cache']['max-age'], 'The block is not cached.');

    $build = $this->createBlock(['00100'], ['Kamppi'])->build();

    $settings = $build['#attached']['drupalSettings']['helfi_react_search'];
    $this->assertEquals(['00100'], $settings['postal_codes'], 'Filter values are fetched again after a failure.');
    $this->assertEquals(['Kamppi'], $settings['resident_parking_zones'], 'Filter values are fetched again after a failure.');
  }

}
