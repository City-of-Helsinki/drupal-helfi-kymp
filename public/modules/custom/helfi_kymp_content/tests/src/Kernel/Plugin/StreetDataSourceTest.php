<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel\Plugin;

use Drupal\helfi_kymp_content\Plugin\search_api\datasource\HelfiStreetDataSource;
use Drupal\helfi_kymp_content\StreetDataService;
use Drupal\KernelTests\KernelTestBase;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests helfi_street_data_source plugin.
 */
class StreetDataSourceTest extends KernelTestBase {

  use ProphecyTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'helfi_kymp_content',
  ];

  /**
   * Tests street data service.
   */
  public function testStreetDataSource(): void {
    $items = [
      'id1' => 'item1',
      'id2' => 'item2',
    ];

    $streetData = $this->prophesize(StreetDataService::class);

    $streetData->getStreetData()
      ->shouldBeCalled()
      ->willReturn($items);

    $this->container->set(StreetDataService::class, $streetData->reveal());

    /** @var \Drupal\Component\Plugin\PluginManagerInterface $pluginManager */
    $pluginManager = $this->container->get('plugin.manager.search_api.datasource');
    $sut = $pluginManager->createInstance('helfi_street_data_source', []);

    $this->assertInstanceOf(HelfiStreetDataSource::class, $sut);
    $this->assertEquals('item1', $sut->load('id1'));
    $this->assertEquals(NULL, $sut->load('does not exist'));
    $this->assertEquals(['id1' => 'item1'], $sut->loadMultiple(['id1']));
    $this->assertEquals($items, $sut->loadMultiple([]));
  }

}
