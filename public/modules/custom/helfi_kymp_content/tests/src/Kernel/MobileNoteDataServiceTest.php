<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests MobileNoteDataService.
 */
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class MobileNoteDataServiceTest extends KernelTestBase {

  use ApiTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
    'search_api',
    'system',
    'user',
  ];

  /**
   * The service under test.
   */
  protected MobileNoteDataService $sut;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('helfi_kymp_content.settings')
      ->set('wfs_url', 'https://example.com/wfs')
      ->set('wfs_username', 'test_user')
      ->set('wfs_password', 'test_pass')
      ->set('address_api_key', 'TEST_KEY')
      ->save();
  }

  /**
   * Tests data fetching and coordinate conversion.
   */
  public function testGetMobileNoteData(): void {
    // Mock WFS API response with EPSG:3879 coordinates.
    // Coordinate approx: 24.953, 60.171.
    $jsonResponse = (string) json_encode([
      'features' => [
        [
          'id' => 'test.123',
          'geometry' => [
            'type' => 'LineString',
            'coordinates' => [
               [25497397.000, 6672506.000],
               [25497500.000, 6672600.000],
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
    ]);

    // Address API response (correct structure).
    $addressResponse = (string) json_encode([
      'results' => [
        [
          'street' => ['name' => ['fi' => 'Mannerheimintie', 'sv' => 'Mannerheimvägen']],
          'location' => ['type' => 'Point', 'coordinates' => [24.941, 60.171]],
        ],
        [
          'street' => ['name' => ['fi' => 'Kaivokatu']],
          'location' => ['type' => 'Point', 'coordinates' => [24.950, 60.175]],
        ],
      ],
    ]);

    // Queue: 1 WFS response, then 1 Address API response (one segment).
    $client = $this->createMockHttpClient([
      new Response(200, [], $jsonResponse),
      new Response(200, [], $addressResponse),
    ]);
    $this->container->set('http_client', $client);

    $this->sut = $this->container->get(MobileNoteDataService::class);

    $data = $this->sut->getMobileNoteData();

    $this->assertCount(1, $data);
    $this->assertArrayHasKey('test.123', $data);

    $this->sut->fetchNearbyStreets($data['test.123']);

    $item = $data['test.123']->getValue();

    // Verify data mapping.
    $this->assertEquals('test.123', $item['id']);
    $this->assertEquals('Test Street 1', $item['address']);
    $this->assertEquals('Test Reason', $item['reason']);
    // Street names should contain all results' fi and sv names.
    $this->assertNotEmpty($item['street_names']);
    $this->assertContains('Mannerheimintie', $item['street_names']);
    $this->assertContains('Mannerheimvägen', $item['street_names']);
    $this->assertContains('Kaivokatu', $item['street_names']);
    $this->assertCount(3, $item['street_names']);

    // Verify date conversion (Europe/Helsinki timezone).
    $tz = new \DateTimeZone('Europe/Helsinki');
    $this->assertEquals((new \DateTime('2026-01-20', $tz))->getTimestamp(), $item['valid_from']);
    // valid_to should be the date + 24 hours (86400 seconds).
    $this->assertEquals((new \DateTime('2026-01-21', $tz))->getTimestamp() + 86400, $item['valid_to']);

    // Verify EPSG:3879 to WGS84 coordinate conversion.
    $coords = $item['geometry']->coordinates[0];

    // Assert coordinates are within expected WGS84 range.
    $this->assertGreaterThan(24, $coords[0]);
    $this->assertLessThan(26, $coords[0]);
    $this->assertGreaterThan(60, $coords[1]);
    $this->assertLessThan(61, $coords[1]);
  }

  /**
   * Tests indexing a MultiLineString geometry feature.
   */
  public function testGetMobileNoteDataMultiLineString(): void {
    // Mock WFS API response with a MultiLineString in EPSG:3879
    // composed of two component LineStrings (one segment each).
    $jsonResponse = (string) json_encode([
      'features' => [
        [
          'id' => 'test.789',
          'geometry' => [
            'type' => 'MultiLineString',
            'coordinates' => [
              [
                [25497397.000, 6672506.000],
                [25497500.000, 6672600.000],
              ],
              [
                [25497700.000, 6672800.000],
                [25497800.000, 6672900.000],
              ],
            ],
          ],
          'properties' => [
            'osoite' => 'Multi Test Street 1',
            'merkinSyy' => ['value' => 'Multi Reason'],
            'voimassaoloAlku' => '2026-01-20',
            'voimassaoloLoppu' => '2026-01-21',
          ],
        ],
      ],
    ]);

    // Address API response shared across both midpoint lookups.
    $addressResponse = (string) json_encode([
      'results' => [
        [
          'street' => ['name' => ['fi' => 'Mannerheimintie', 'sv' => 'Mannerheimvägen']],
          'location' => ['type' => 'Point', 'coordinates' => [24.941, 60.171]],
        ],
        [
          'street' => ['name' => ['fi' => 'Kaivokatu']],
          'location' => ['type' => 'Point', 'coordinates' => [24.950, 60.175]],
        ],
      ],
    ]);

    // Queue: 1 WFS response, then 2 Address API responses (one per segment).
    $client = $this->createMockHttpClient([
      new Response(200, [], $jsonResponse),
      new Response(200, [], $addressResponse),
      new Response(200, [], $addressResponse),
    ]);
    $this->container->set('http_client', $client);
    $this->sut = $this->container->get(MobileNoteDataService::class);

    $data = $this->sut->getMobileNoteData();

    $this->assertCount(1, $data);
    $this->assertArrayHasKey('test.789', $data);

    $this->sut->fetchNearbyStreets($data['test.789']);

    $item = $data['test.789']->getValue();

    // Geometry: type preserved (lowercased), coordinates nested two levels.
    $this->assertEquals('multilinestring', $item['geometry']->type);
    $this->assertCount(2, $item['geometry']->coordinates);
    $this->assertCount(2, $item['geometry']->coordinates[0]);
    $this->assertCount(2, $item['geometry']->coordinates[1]);

    // First point of the first component must be transformed to WGS84.
    [$lon, $lat] = $item['geometry']->coordinates[0][0];
    $this->assertGreaterThan(24, $lon);
    $this->assertLessThan(26, $lon);
    $this->assertGreaterThan(60, $lat);
    $this->assertLessThan(61, $lat);

    // Last point of the second component must also be transformed to WGS84.
    [$lon2, $lat2] = $item['geometry']->coordinates[1][1];
    $this->assertGreaterThan(24, $lon2);
    $this->assertLessThan(26, $lon2);
    $this->assertGreaterThan(60, $lat2);
    $this->assertLessThan(61, $lat2);

    // Street names: merged across all components and deduplicated.
    $this->assertNotEmpty($item['street_names']);
    $this->assertContains('Mannerheimintie', $item['street_names']);
    $this->assertContains('Mannerheimvägen', $item['street_names']);
    $this->assertContains('Kaivokatu', $item['street_names']);
    $this->assertCount(3, $item['street_names']);

    // Map URL: built as a MULTIPOLYGON WKT.
    $this->assertNotEmpty($item['map_url']);
    $this->assertStringStartsWith('https://kartta.hel.fi/', $item['map_url']);
    $this->assertStringContainsString('MULTIPOLYGON', rawurldecode($item['map_url']));
  }

  /**
   * Tests fetching data without enrichment.
   */
  public function testGetMobileNoteDataNoEnrich(): void {
    // Mock WFS API response.
    $jsonResponse = (string) json_encode([
      'features' => [
        [
          'id' => 'test.456',
          'geometry' => [
            'type' => 'LineString',
            'coordinates' => [[25497397.000, 6672506.000]],
          ],
          'properties' => [
            'osoite' => 'Test Street 2',
            'merkinSyy' => ['value' => 'Test Reason'],
            'voimassaoloAlku' => '2026-01-20',
            'voimassaoloLoppu' => '2026-01-21',
          ],
        ],
      ],
    ]);

    // Queue only the WFS response. If anything tries to call the
    // Address API, MockHandler will throw because the queue is empty.
    $client = $this->createMockHttpClient([
      new Response(200, [], $jsonResponse),
    ]);
    $this->container->set('http_client', $client);
    $this->sut = $this->container->get(MobileNoteDataService::class);

    // Call without enrichment.
    $data = $this->sut->getMobileNoteData();

    $this->assertCount(1, $data);
    $this->assertArrayHasKey('test.456', $data);
    $item = $data['test.456']->getValue();
    $this->assertEquals('Test Street 2', $item['address']);
    $this->assertArrayNotHasKey('street_names', $item);
  }

}
