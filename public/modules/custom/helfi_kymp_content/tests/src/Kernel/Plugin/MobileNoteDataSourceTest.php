<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel\Plugin;

use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\helfi_kymp_content\Plugin\search_api\datasource\MobileNoteDataSource;
use Drupal\KernelTests\KernelTestBase;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests MobileNoteDataSource plugin.
 *
 * @group helfi_kymp_content
 */
class MobileNoteDataSourceTest extends KernelTestBase {

  use ProphecyTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'helfi_kymp_content',
    'user',
    'system',
  ];

  /**
   * Tests datasource loading.
   */
  public function testDataSource(): void {
    $items = [
      'test_id' => 'test_data',
    ];

    $service = $this->prophesize(MobileNoteDataService::class);
    $service->getMobileNoteData()->willReturn($items);

    $this->container->set(MobileNoteDataService::class, $service->reveal());

    /** @var \Drupal\search_api\Datasource\DatasourceInterface $datasource */
    $datasource = $this->container->get('plugin.manager.search_api.datasource')
      ->createInstance('mobilenote_data_source');

    $this->assertInstanceOf(MobileNoteDataSource::class, $datasource);
    $this->assertEquals(['test_id' => 'test_data'], $datasource->loadMultiple(['test_id']));
    $this->assertEquals('test_data', $datasource->load('test_id'));
    $this->assertNull($datasource->load('non_existent'));
  }

}
