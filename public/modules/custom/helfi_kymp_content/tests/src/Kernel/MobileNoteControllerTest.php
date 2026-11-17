<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Core\Http\ClientFactory;
use Drupal\helfi_api_base\Environment\EnvironmentEnum;
use Drupal\helfi_api_base\Environment\Project;
use Drupal\helfi_kymp_content\Controller\MobileNoteController;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\helfi_api_base\Traits\EnvironmentResolverTrait;
use Drupal\Tests\helfi_platform_config\Kernel\KernelTestBase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use function PHPUnit\Framework\assertNotEmpty;

/**
 * Tests MobileNoteController.
 */
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class MobileNoteControllerTest extends KernelTestBase {

  use ApiTestTrait;
  use EnvironmentResolverTrait;

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
   * Requests sent to Elasticsearch.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $elasticHistory = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setActiveProject(Project::LIIKENNE, EnvironmentEnum::Local);
    $this->config('helfi_kymp_content.settings')
      ->set('address_api_key', 'TEST_KEY')
      ->save();
  }

  /**
   * Tests coordinates missing.
   */
  public function testMissingCoordinates(): void {
    $controller = $this->getController([]);
    $response = $controller->getMobileNoteData($this->createRequest(lat: NULL, lon: NULL));
    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * Tests a proper request.
   */
  public function testParkingZone(): void {
    $hit = ['_id' => '1', '_source' => ['address' => ['Mannerheimintie 1']]];
    $controller = $this->getController(
      [$this->parkingZoneResponse('Kluuvi/Kruununhaka')],
      [$this->elasticResponse([$hit])],
    );

    $response = $controller->getMobileNoteData($this->createRequest());
    $this->assertEquals(200, $response->getStatusCode());

    $response = $response->getContent();
    assertNotEmpty($response);
    $data = json_decode($response, TRUE);

    $this->assertEquals('Kluuvi/Kruununhaka', $data['parkingzone']);
    $this->assertArrayNotHasKey('postalcodes', $data);
    $this->assertEquals([$hit], $data['results']);
  }

  /**
   * Tests a proper request.
   */
  public function testPostalCode(): void {
    $hit = ['_id' => '1', '_source' => ['address' => ['Mannerheimintie 1']]];
    $controller = $this->getController(
      [
        $this->parkingZoneResponse(NULL),
        $this->addressResponse(['00100', '00100', '00130']),
      ],
      [$this->elasticResponse([$hit])],
    );

    $response = $controller->getMobileNoteData($this->createRequest());
    $this->assertEquals(200, $response->getStatusCode());

    $response = $response->getContent();
    assertNotEmpty($response);
    $data = json_decode($response, TRUE);
    $this->assertEquals('00100,00130', $data['postalcodes']);
    $this->assertEquals([$hit], $data['results']);
  }

  /**
   * Create a request with the given coordinates.
   */
  private function createRequest(?string $lat = '60.17', ?string $lon = '24.94'): Request {
    return Request::create('/api/v1/mobilenote', 'GET', array_filter(['lat' => $lat, 'lon' => $lon]));
  }

  /**
   * Creates the controller with mocked responses.
   *
   * @param array<\Psr\Http\Message\ResponseInterface> $apiResponses
   *   The api responses.
   * @param array<\Psr\Http\Message\ResponseInterface> $elasticResponses
   *   The elastic responses.
   *
   * @return \Drupal\helfi_kymp_content\Controller\MobileNoteController
   *   The controller.
   */
  private function getController(array $apiResponses, array $elasticResponses = []): MobileNoteController {
    $this->container->set('http_client', $this->createMockHttpClient($apiResponses));

    $elasticHttpClient = $this->createMockHistoryMiddlewareHttpClient($this->elasticHistory, $elasticResponses);
    $clientFactory = $this->createMock(ClientFactory::class);
    $clientFactory->method('fromOptions')->willReturn($elasticHttpClient);
    $this->container->set('http_client_factory', $clientFactory);

    return $this->container
      ->get('class_resolver')
      ->getInstanceFromDefinition(MobileNoteController::class);
  }

  /**
   * Creates a Servicemap parking zone response.
   */
  private function parkingZoneResponse(?string $name): Response {
    $results = [];
    if ($name) {
      $results[] = [
        'name' => ['fi' => $name],
        'boundary' => [
          'type' => 'MultiPolygon',
          'coordinates' => [[[[24.94, 60.17], [24.95, 60.17], [24.95, 60.18], [24.94, 60.17]]]],
        ],
      ];
    }
    return new Response(200, body: json_encode(['results' => $results], JSON_THROW_ON_ERROR));
  }

  /**
   * Create a Paikkatieto address response.
   *
   * @param array<string|null> $postalCodes
   *   Postal code per address, NULL for an address without one.
   */
  private function addressResponse(array $postalCodes): Response {
    $results = array_map(fn (?string $code) => array_filter([
      'street' => ['name' => ['fi' => 'Kaivokatu']],
      'postal_code_area' => $code ? ['postal_code' => $code] : NULL,
    ]), $postalCodes);

    return new Response(200, body: json_encode(['results' => $results], JSON_THROW_ON_ERROR));
  }

  /**
   * Creates an Elasticsearch search response.
   *
   * @param array<int, array<string, mixed>> $hits
   *   The hits.
   * @param int $status
   *   The status code.
   */
  private function elasticResponse(array $hits, int $status = 200): Response {
    return new Response($status, [
      'Content-Type' => 'application/json',
      'X-Elastic-Product' => 'Elasticsearch',
    ], json_encode([
      'hits' => ['total' => ['value' => count($hits)], 'hits' => $hits],
    ], JSON_THROW_ON_ERROR));
  }

}
