<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests MobileNoteDataSource.
 */
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class MobileNoteDataSourceTest extends KernelTestBase {

  use ApiTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
    'helfi_api_base',
    'search_api',
    'user',
    'diff',
  ];

  /**
   * Tests data fetching and coordinate conversion.
   */
  public function testDatasource(): void {
    $this->config('helfi_kymp_content.settings')
      ->set('wfs_url', 'https://example.com/wfs')
      ->set('wfs_username', 'test_user')
      ->set('wfs_password', 'test_pass')
      ->save();

    $parkingZoneResponse = (string) json_encode([
      'results' => [[
        'name' => ['fi' => 'Kamppi'],
        'boundary' => ['coordinates' => [[[24.950, 60.175], [24.951, 60.175]], [[24.949, 60.174], [24.950, 60.175]]]],
      ],
      ],
    ]);

    $this->setupMockHttpClient([
      new Response(body: (string) json_encode([
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
      new Response(200, [], $parkingZoneResponse),
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

    $items = $datasource->loadMultiple(['test.123']);

    $this->assertCount(1, $items);
    $this->assertTrue(array_all($items, static fn ($item) => $item instanceof MobileNoteData));

    $this->assertArrayHasKey('test.123', $items);
    $item = $items['test.123']->getValue();

    // Verify data mapping.
    $this->assertEquals('test.123', $item['id']);
    $this->assertEquals('Test Street 1 Info', $item['address']);
    $this->assertEquals('Test Reason', $item['reason']);
    $this->assertEquals('Extra', $item['additional_text']);

    // Verify date conversion (Europe/Helsinki timezone).
    $tz = new \DateTimeZone('Europe/Helsinki');
    $this->assertEquals((new \DateTime('2026-01-20', $tz))->getTimestamp(), $item['valid_from']);

    // Verify EPSG:3879 to WGS84 coordinate conversion.
    $coords = $item['geometry']->coordinates[0];

    // Assert coordinates are within expected WGS84 range.
    $this->assertGreaterThan(24, $coords[0]);
    $this->assertLessThan(26, $coords[0]);
    $this->assertGreaterThan(60, $coords[1]);
    $this->assertLessThan(61, $coords[1]);
  }

}
