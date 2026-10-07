<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\helfi_api_base\Environment\EnvironmentEnum;
use Drupal\helfi_api_base\Environment\EnvironmentResolverInterface;
use Drupal\helfi_api_base\Environment\Project;
use Drupal\helfi_hakuvahti\DrupalSettings;
use Drupal\helfi_kymp_content\Plugin\Block\VehicleRemovalBlock;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\EnvironmentResolverTrait;
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
   * Tests the block build output.
   */
  public function testBuild(): void {
    $this->installConfig(['helfi_hakuvahti']);

    $this->setActiveProject(Project::LIIKENNE, EnvironmentEnum::Local);

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
      $this->container->get(EnvironmentResolverInterface::class),
    );

    $build = $block->build();

    $this->assertEquals('vehicle_removal', $build['#theme']);
    $this->assertContains('helfi_kymp_content/vehicle-removal-search', $build['#attached']['library']);
    $this->assertEquals('https://elastic-proxy-helfi-kymp.docker.so', $build['#attached']['drupalSettings']['helfi_react_search']['elastic_proxy_url']);
    $this->assertEquals('https://sentry.example.com/123', $build['#attached']['drupalSettings']['helfi_react_search']['sentry_dsn_react']);
    $this->assertContains('config:helfi_api_base.environment_resolver.settings', $build['#cache']['tags']);
    $this->assertContains('config:react_search.settings', $build['#cache']['tags']);
  }

}
