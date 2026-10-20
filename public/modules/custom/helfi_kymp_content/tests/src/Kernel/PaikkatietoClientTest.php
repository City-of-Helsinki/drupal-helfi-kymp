<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\helfi_kymp_content\Paikkatieto\Exception;
use Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests PaikkatietoClient.
 */
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class PaikkatietoClientTest extends KernelTestBase {

  use ApiTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
    'helfi_api_base',
    'system',
    'diff',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('helfi_kymp_content.settings')
      ->set('address_api_key', 'TEST_KEY')
      ->save();
  }

  /**
   * Creates a PaikkatietoClient with mocked HTTP responses.
   *
   * @param array<\Psr\Http\Message\ResponseInterface|\GuzzleHttp\Exception\GuzzleException> $responses
   *   The queued HTTP responses.
   *
   * @return \Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient
   *   The client.
   */
  private function createClient(array $responses): PaikkatietoClient {
    $httpClient = $this->createMockHttpClient($responses);
    $this->container->set('http_client', $httpClient);
    return $this->container->get(PaikkatietoClient::class);
  }

  /**
   * Creates an address API JSON response.
   *
   * @param array<array{fi?: string, sv?: string, en?: string}> $streets
   *   Street names keyed by language.
   *
   * @return \GuzzleHttp\Psr7\Response
   *   A mock response.
   */
  private function createAddressResponse(array $streets): Response {
    $results = [];
    foreach ($streets as $names) {
      $nameObj = new \stdClass();
      foreach ($names as $lang => $name) {
        $nameObj->{$lang} = $name;
      }
      $results[] = ['street' => ['name' => $nameObj]];
    }
    return new Response(body: json_encode(['results' => $results], JSON_THROW_ON_ERROR));
  }

  /**
   * Tests fetching addresses by point.
   */
  public function testFetchAddressesByPoint(): void {
    $history = [];
    $this->container->set('http_client', $this->createMockHistoryMiddlewareHttpClient($history, [
      new Response(body: json_encode([
        'results' => [
          ['street' => ['name' => ['fi' => 'Kasarmikatu']], 'number' => '13'],
        ],
      ], JSON_THROW_ON_ERROR)),
      new Response(body: json_encode(['results' => []], JSON_THROW_ON_ERROR)),
    ]));
    $client = $this->container->get(PaikkatietoClient::class);

    $results = $client->fetchAddressesByPoint(60.1618, 24.948);

    $this->assertCount(1, $results);
    $this->assertEquals('13', $results[0]->number ?? NULL);

    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    $this->assertEquals('75', $query['distance']);
    $this->assertEquals('20', $query['limit']);

    // Tests fetching addresses by point with empty results.
    $this->assertEmpty($client->fetchAddressesByPoint(60.191, 24.931));
  }

  /**
   * Tests fetching addresses for a geometry.
   */
  public function testFetchAddressesForGeometry(): void {
    $client = $this->createClient([
      // LineString segment 1 midpoint response.
      $this->createAddressResponse([
        ['fi' => 'Mannerheimintie', 'sv' => 'Mannerheimvägen'],
      ]),
      // LineString segment 2 midpoint response.
      $this->createAddressResponse([
        ['fi' => 'Mannerheimintie', 'sv' => 'Mannerheimvägen'],
        ['fi' => 'Kaivokatu'],
      ]),
      // MultiLineString component midpoint responses.
      $this->createAddressResponse([['fi' => 'Kaivokatu']]),
      $this->createAddressResponse([['fi' => 'Kaivokatu']]),
    ]);

    $results = $client->fetchAddressesForGeometry((object) [
      'type' => 'linestring',
      'coordinates' => [
        [24.941, 60.171],
        [24.945, 60.173],
        [24.950, 60.175],
      ],
    ]);
    $this->assertCount(3, $results);

    $results = $client->fetchAddressesForGeometry((object) [
      'type' => 'multilinestring',
      'coordinates' => [
        [[24.941, 60.171], [24.945, 60.173]],
        [[24.950, 60.175], [24.951, 60.176]],
      ],
    ]);
    $this->assertCount(2, $results);

    $this->expectException(Exception::class);
    $client->fetchAddressesForGeometry((object) ['type' => 'point', 'coordinates' => [24.95, 60.17]]);
  }

  /**
   * Tests fetching street names by page.
   */
  public function testFetchStreetNamesByPage(): void {
    $client = $this->createClient([
      $this->createAddressResponse([
        ['fi' => 'Mannerheimintie', 'sv' => 'Mannerheimvägen'],
        ['fi' => 'Kaivokatu'],
      ]),
      new RequestException(
        'Not Found',
        new Request('GET', 'test'),
        new Response(404, [], json_encode(['detail' => 'error'], JSON_THROW_ON_ERROR)),
      ),
      new RequestException(
        'Server Error',
        new Request('GET', 'test'),
        new Response(500),
      ),
    ]);

    $results = $client->fetchStreetNamesByPage(1);

    $this->assertNotNull($results);
    $this->assertCount(3, $results);

    $this->assertEquals('Mannerheimintie', $results[0]['name']);
    $this->assertEquals('fi', $results[0]['language']);
    $this->assertEquals('Mannerheimvägen', $results[1]['name']);
    $this->assertEquals('sv', $results[1]['language']);
    $this->assertEquals('Kaivokatu', $results[2]['name']);
    $this->assertEquals('fi', $results[2]['language']);

    // Tests that fetching past the last page returns NULL.
    $result = $client->fetchStreetNamesByPage(999);
    $this->assertNull($result);

    // Tests that non-404 errors are rethrown.
    $this->expectException(Exception::class);
    $client->fetchStreetNamesByPage(1);
  }

  /**
   * Tests that missing API key throws an exception.
   */
  public function testMissingApiKeyThrows(): void {
    $this->config('helfi_kymp_content.settings')
      ->set('address_api_key', '')
      ->save();

    $client = $this->createClient([]);

    $this->expectException(\InvalidArgumentException::class);
    $client->fetchAddressesByPoint(60.171, 24.941);
  }

  /**
   * Tests retry on 502 followed by success.
   */
  public function testRetryOn502ThenSuccess(): void {
    $client = $this->createClient([
      new RequestException(
        'Bad Gateway',
        new Request('GET', 'test'),
        new Response(502),
      ),
      $this->createAddressResponse([
        ['fi' => 'Kaivokatu'],
      ]),
      new RequestException(
        'Forbidden',
        new Request('GET', 'test'),
        new Response(403),
      ),
    ]);

    $results = $client->fetchAddressesByPoint(60.171, 24.941);

    $this->assertCount(1, $results);
    $this->assertEquals('Kaivokatu', $results[0]->street->name->fi ?? NULL);

    // Tests that non-502 errors throw immediately without retrying.
    $this->expectException(Exception::class);
    $client->fetchAddressesByPoint(60.171, 24.941);
  }

}
