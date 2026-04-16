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
    'system',
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
   * Tests fetching street names by point.
   */
  public function testFetchStreetsByPoint(): void {
    $client = $this->createClient([
      $this->createAddressResponse([
        ['fi' => 'Mannerheimintie', 'sv' => 'Mannerheimvägen'],
        ['fi' => 'Mannerheimintie', 'sv' => 'Mannerheimvägen'],
        ['fi' => 'Kaivokatu', 'sv' => 'Brunnsgatan'],
      ]),
      new Response(body: json_encode(['results' => []], JSON_THROW_ON_ERROR)),
    ]);

    // Tests fetching street names by point.
    $streets = $client->fetchStreetsByPoint(60.171, 24.941);

    $this->assertCount(4, $streets);
    $this->assertContains('Mannerheimintie', $streets);
    $this->assertContains('Mannerheimvägen', $streets);
    $this->assertContains('Kaivokatu', $streets);
    $this->assertContains('Brunnsgatan', $streets);

    // Tests fetching street names by point with empty results.
    $streets = $client->fetchStreetsByPoint(60.191, 24.931);

    $this->assertEmpty($streets);
  }

  /**
   * Tests fetching streets for a linestring.
   */
  public function testFetchStreetsForLineString(): void {
    $client = $this->createClient([
      // Segment 1 midpoint response.
      $this->createAddressResponse([
        ['fi' => 'Mannerheimintie', 'sv' => 'Mannerheimvägen'],
      ]),
      // Segment 2 midpoint response.
      $this->createAddressResponse([
        ['fi' => 'Mannerheimintie', 'sv' => 'Mannerheimvägen'],
        ['fi' => 'Kaivokatu'],
      ]),
    ]);

    $coordinates = [
      [24.941, 60.171],
      [24.945, 60.173],
      [24.950, 60.175],
    ];

    $streets = $client->fetchStreetsForLineString($coordinates);

    // Deduplicated across segments.
    $this->assertCount(3, $streets);
    $this->assertContains('Mannerheimintie', $streets);
    $this->assertContains('Mannerheimvägen', $streets);
    $this->assertContains('Kaivokatu', $streets);
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
    $client->fetchStreetsByPoint(60.171, 24.941);
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

    $streets = $client->fetchStreetsByPoint(60.171, 24.941);

    $this->assertCount(1, $streets);
    $this->assertContains('Kaivokatu', $streets);

    // Tests that non-502 errors throw immediately without retrying.
    $this->expectException(Exception::class);
    $client->fetchStreetsByPoint(60.171, 24.941);
  }

}
