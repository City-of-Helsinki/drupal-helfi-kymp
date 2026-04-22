<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Entity\Index;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests PaikkatietoStreetNameDataSource.
 */
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class PaikkatietoStreetNameDataSourceTest extends KernelTestBase {

  use ProphecyTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
    'search_api',
    'user',
  ];

  /**
   * Creates the datasource from a test index.
   *
   * @return \Drupal\search_api\Datasource\DatasourceInterface
   *   The datasource plugin.
   */
  private function getDatasource(): DatasourceInterface {
    $index = Index::create([
      'name' => 'Test Index',
      'id' => 'test_paikkatieto_street_names',
      'status' => FALSE,
      'datasource_settings' => [
        'paikkatieto_street_name_source' => [],
      ],
      'tracker_settings' => [
        'default' => [],
      ],
    ]);
    return $index->getDatasource('paikkatieto_street_name_source');
  }

  /**
   * Tests getItemIds returns deduplicated IDs.
   */
  public function testGetItemIds(): void {
    $client = $this->prophesize(PaikkatietoClient::class);
    $client->fetchStreetNamesByPage(1)
      ->shouldBeCalled()
      ->willReturn([
        ['name' => 'Mannerheimintie', 'language' => 'fi'],
        ['name' => 'Mannerheimintie', 'language' => 'fi'],
        ['name' => 'Mannerheimvägen', 'language' => 'sv'],
        ['name' => 'Kaivokatu', 'language' => 'fi'],
      ]);
    $this->container->set(PaikkatietoClient::class, $client->reveal());

    $datasource = $this->getDatasource();
    $ids = $datasource->getItemIds(0);

    // Duplicates should be removed.
    $this->assertCount(3, $ids);
    $this->assertContains('fi:Mannerheimintie', $ids);
    $this->assertContains('sv:Mannerheimvägen', $ids);
    $this->assertContains('fi:Kaivokatu', $ids);
  }

  /**
   * Tests that getItemIds returns NULL on InvalidArgumentException.
   */
  public function testGetItemIdsReturnsNullOnInvalidArgumentException(): void {
    $client = $this->prophesize(PaikkatietoClient::class);
    $client->fetchStreetNamesByPage(1)
      ->shouldBeCalled()
      ->willThrow(new \InvalidArgumentException('Missing API key'));
    $this->container->set(PaikkatietoClient::class, $client->reveal());

    $datasource = $this->getDatasource();
    $this->assertNull($datasource->getItemIds(0));
  }

  /**
   * Tests loading a single item from its ID.
   */
  public function testLoad(): void {
    $datasource = $this->getDatasource();
    $item = $datasource->load('fi:Mannerheimintie');

    $this->assertInstanceOf(ComplexDataInterface::class, $item);
    $this->assertEquals('fi:Mannerheimintie', $item->get('id')->getString());
    $this->assertEquals('Mannerheimintie', $item->get('street_name')->getString());
    $this->assertEquals('fi', $item->get('language')->getString());
  }

  /**
   * Tests loading multiple items.
   */
  public function testLoadMultiple(): void {
    $datasource = $this->getDatasource();
    $items = $datasource->loadMultiple(['fi:Mannerheimintie', 'sv:Mannerheimvägen']);

    $this->assertCount(2, $items);
    $this->assertArrayHasKey('fi:Mannerheimintie', $items);
    $this->assertArrayHasKey('sv:Mannerheimvägen', $items);
  }

}
