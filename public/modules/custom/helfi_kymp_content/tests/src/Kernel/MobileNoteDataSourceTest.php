<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests MobileNoteDataSource.
 */
#[Group('helfi_kymp_content')]
class MobileNoteDataSourceTest extends KernelTestBase {

  use ApiTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
    'search_api',
    'user',
  ];

  /**
   * Tests data fetching and coordinate conversion.
   */
  public function testDatasource(): void {
    new Settings([
      'helfi_kymp_mobilenote' => [
        'wfs_url' => 'https://example.com/wfs',
        'wfs_username' => 'test_user',
        'wfs_password' => 'test_pass',
        'sync_lookback_offset' => '-30 days',
      ],
    ]);

    $this->setupMockHttpClient([
      new Response(body: json_encode([
        'features' => [
          [
            'id' => 'test.123',
            'geometry' => [
              'type' => 'LineString',
              'coordinates' => [
                [25497397.000, 6672506.000],
              ],
            ],
            'properties' => [
              'osoite' => 'Test Street 1',
              'merkinSyy' => ['value' => 'Test Reason'],
              'voimassaoloAlku' => '2026-01-20',
              'voimassaoloLoppu' => '2026-01-21',
              'kello' => '8-16',
              'luontipvm' => '2026-01-19T12:00:00',
              'paivityspvm' => '2026-01-20T10:00:00',
              'osoitteenlisatieto' => 'Info',
              'merkinLaatu' => ['value' => 'Type'],
              'lisakilvenTeksti' => 'Extra',
              'huomautukset' => 'Notes',
              'puhelinnumero' => '12345',
            ],
          ],
        ],
      ])),
    ]);

    // Create a test index.
    $index = Index::create([
      'name' => 'Test Index',
      'id' => 'test_index',
      'status' => FALSE,
      'datasource_settings' => [
        'mobilenote_data_source' => [],
      ],
      'tracker_settings' => [
        'default' => [],
      ],
    ]);
    $datasource = $index->getDatasource('mobilenote_data_source');

    $items = $datasource->loadMultiple([]);

    $this->assertCount(1, $items);
    $this->assertTrue(array_all($items, static fn ($item) => $item instanceof MobileNoteData));

    $this->assertArrayHasKey('test.123', $items);
    $item = $items['test.123']->getValue();

    // Verify data mapping.
    $this->assertEquals('test.123', $item['id']);
    $this->assertEquals('Test Street 1', $item['address']);
    $this->assertEquals('Test Reason', $item['reason']);
    $this->assertEquals('Extra', $item['additional_text']);

    // Verify date conversion.
    $this->assertEquals(strtotime('2026-01-20'), $item['valid_from']);

    // Verify EPSG:3879 to WGS84 coordinate conversion.
    $coords = $item['geometry']->coordinates[0];

    // Assert coordinates are within expected WGS84 range.
    $this->assertGreaterThan(24, $coords[0]);
    $this->assertLessThan(26, $coords[0]);
    $this->assertGreaterThan(60, $coords[1]);
    $this->assertLessThan(61, $coords[1]);
  }

}
