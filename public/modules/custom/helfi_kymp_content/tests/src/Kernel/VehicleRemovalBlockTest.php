<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\helfi_hakuvahti\DrupalSettings;
use Drupal\helfi_kymp_content\Plugin\Block\VehicleRemovalBlock;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests VehicleRemovalBlock.
 *
 * @group helfi_kymp_content
 */
class VehicleRemovalBlockTest extends KernelTestBase {

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
  ];

  /**
   * Tests the block build output.
   */
  public function testBuild(): void {
    $this->installConfig(['helfi_hakuvahti']);

    $this->config('elastic_proxy.settings')
      ->set('elastic_proxy_url', 'https://example.com/proxy')
      ->save();

    $this->config('react_search.settings')
      ->set('sentry_dsn_react', 'https://sentry.example.com/123')
      ->save();

    /** @var \Drupal\helfi_hakuvahti\DrupalSettings $drupalSettings */
    $drupalSettings = $this->container->get(DrupalSettings::class);

    $block = new VehicleRemovalBlock(
      [],
      'kymp_vehicle_removal',
      ['provider' => 'helfi_kymp_content'],
      $drupalSettings,
      $this->container->get('config.factory'),
    );

    $build = $block->build();

    $this->assertEquals('vehicle_removal', $build['#theme']);
    $this->assertContains('helfi_kymp_content/vehicle-removal-search', $build['#attached']['library']);
    $this->assertEquals('https://example.com/proxy', $build['#attached']['drupalSettings']['helfi_react_search']['elastic_proxy_url']);
    $this->assertEquals('https://sentry.example.com/123', $build['#attached']['drupalSettings']['helfi_react_search']['sentry_dsn_react']);
    $this->assertContains('config:elastic_proxy.settings', $build['#cache']['tags']);
    $this->assertContains('config:react_search.settings', $build['#cache']['tags']);
  }

}
