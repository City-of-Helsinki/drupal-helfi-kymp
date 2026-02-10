<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests MobileNoteDataService.
 *
 * @group helfi_kymp_content
 */
class MobileNoteDataServiceTest extends KernelTestBase {

  use ProphecyTrait;

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

    // Mock settings.
    $settings = Settings::getAll();
    $settings['helfi_kymp_mobilenote'] = [
      'wfs_url' => 'https://example.com/wfs',
      'wfs_username' => 'test_user',
      'wfs_password' => 'test_pass',
      'sync_lookback_offset' => '-30 days',
      'address_api_key' => 'TEST_KEY',
    ];
    new Settings($settings);
  }

  /**
   * Tests data fetching and coordinate conversion.
   */
  public function testGetMobileNoteData(): void {
    // Mock WFS API response with EPSG:3879 coordinates.
    // Coordinate approx: 24.953, 60.171.
    $jsonResponse = json_encode([
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
    ]);

    // Mock Address API response (correct structure).
    $addressResponse = json_encode([
      'results' => [
        ['street' => ['name' => ['fi' => 'Mannerheimintie']]],
        ['street' => ['name' => ['fi' => 'Kaivokatu']]],
      ],
    ]);

    $client = $this->prophesize(ClientInterface::class);
    $client->request('GET', 'https://example.com/wfs', Argument::any())
      ->shouldBeCalled()
      ->willReturn(new Response(200, [], $jsonResponse));

    $client->request('GET', 'https://paikkatietohaku.api.hel.fi/v1/address/', Argument::that(function ($args) {
       return !empty($args['query']['lat']) && !empty($args['query']['lon']) && ($args['headers']['Api-Key'] ?? '') === 'TEST_KEY';
    }))
      ->shouldBeCalled()
      ->willReturn(new Response(200, [], $addressResponse));

    $this->container->set('http_client', $client->reveal());

    $this->sut = $this->container->get(MobileNoteDataService::class);

    $data = $this->sut->getMobileNoteData();
    $this->sut->fetchNearbyStreets($data);

    $this->assertCount(1, $data);
    $this->assertArrayHasKey('test.123', $data);

    $item = $data['test.123']->getValue();

    // Verify data mapping.
    $this->assertEquals('test.123', $item['id']);
    $this->assertEquals('Test Street 1', $item['address']);
    $this->assertEquals('Test Reason', $item['reason']);
    // 5. Assertions.
    $this->assertNotEmpty($item['street_names']);
    $this->assertContains('Mannerheimintie', $item['street_names']);
    $this->assertContains('Kaivokatu', $item['street_names']);
    $this->assertCount(2, $item['street_names']);

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

  /**
   * Tests fetching data without enrichment.
   */
  public function testGetMobileNoteDataNoEnrich(): void {
    // Mock WFS API response.
    $jsonResponse = json_encode([
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
          ],
        ],
      ],
    ]);

    $client = $this->prophesize(ClientInterface::class);
    $client->request('GET', 'https://example.com/wfs', Argument::any())
      ->shouldBeCalled()
      ->willReturn(new Response(200, [], $jsonResponse));

    // Ensure Address API is NOT called.
    $client->request('GET', 'https://paikkatietohaku.api.hel.fi/v1/address/', Argument::any())
      ->shouldNotBeCalled();

    $this->container->set('http_client', $client->reveal());
    $this->sut = $this->container->get(MobileNoteDataService::class);

    // Call with $enrich = FALSE.
    $data = $this->sut->getMobileNoteData();

    $this->assertCount(1, $data);
    $this->assertArrayHasKey('test.456', $data);
    $item = $data['test.456']->getValue();
    $this->assertEquals('Test Street 2', $item['address']);
    $this->assertArrayNotHasKey('street_names', $item);
  }

}
