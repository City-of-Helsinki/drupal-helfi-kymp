<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use Drupal\helfi_api_base\Environment\ActiveServiceTrait;
use Drupal\helfi_api_base\Environment\EnvironmentResolverException;
use Drupal\helfi_api_base\Environment\EnvironmentResolverInterface;
use Drupal\helfi_api_base\Environment\ServiceEnum;
use Drupal\helfi_api_base\ServiceMap\DTO\Location;
use Drupal\helfi_api_base\ServiceMap\ResidentParkingZoneService;
use Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder as ElasticClientBuilder;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Allow other instances to access kymp mobile note data.
 */
final class MobileNoteController extends ControllerBase {

  public function __construct(
    #[Autowire('logger.channel.helfi_kymp_content')]
    private LoggerChannelInterface $logger,
    protected readonly ClientFactory $clientFactory,
    protected readonly EnvironmentResolverInterface $environmentResolver,
    protected readonly ResidentParkingZoneService $parkingZoneService,
    protected readonly PaikkatietoClient $paikkatietoClient,
  ) {
  }

  /**
   * Get mobile note data by lat/lon.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function getMobileNoteData(Request $request): JsonResponse {
    $lon = $request->query->get('lon');
    $lat = $request->query->get('lat');
    if (!$lon || !$lat) {
      return new JsonResponse(['message' => 'Missing lat or lon'], 400);
    }

    $parkingZoneName = NULL;
    $postalCodes = NULL;
    $location = Location::createFromArray([
      'coordinates' => [$lon, $lat],
      'type' => 'resident_parking_zone',
    ]);

    try {
      $parkingZone = $this->parkingZoneService->getParkingZone($location);
    }
    catch (\Exception $e) {
      Error::logException($this->logger, $e);
      return new JsonResponse(['message' => 'Failed to fetch the parking zone.'], 500);
    }

    // If the address is not inside a parking zone, use postal code.
    if ($parkingZone) {
      $parkingZoneName = $parkingZone->name;
      $elasticQuery = $this->getElasticQuery(parkingZone: $parkingZoneName);
    }
    else {
      try {
        $postalCodes = $this->getPostalCodes($lat, $lon);
      }
      catch (\Exception $e) {
        Error::logException($this->logger, $e);
        return new JsonResponse(['message' => 'Failed to fetch postal codes for the address'], 500);
      }
      $elasticQuery = $this->getElasticQuery(postalCodes: $postalCodes);
    }

    // Query Elasticsearch.
    $client = $this->getElasticClient();
    try {
      $elasticResponse = $client->search([
        'index' => 'mobilenote_data',
        'body' => $elasticQuery,
      ]);
      assert($elasticResponse instanceof Elasticsearch);
      $mobileNotes = $elasticResponse->asArray();
    }
    catch (\Exception $e) {
      Error::logException($this->logger, $e);
      return new JsonResponse(['message' => $e->getMessage()], 500);
    }

    $response = [];
    if ($parkingZoneName) {
      $response['parkingzone'] = $parkingZoneName;
    }
    if ($postalCodes) {
      $response['postalcodes'] = implode(',', $postalCodes);
    }
    $response['results'] = $mobileNotes['hits']['hits'] ?? [];

    return new JsonResponse($response);
  }

  /**
   * Get postal codes from paikkatieto-api.
   *
   * @param string $lat
   *   The lat coordinate.
   * @param string $lon
   *   The lon coordinate.
   *
   * @return array<string>
   *   The postal codes.
   */
  private function getPostalCodes(string $lat, string $lon): array {
    $addresses = $this->paikkatietoClient->fetchAddressesByPoint(floatval($lat), floatval($lon));
    $postalCodes = array_map(static fn($address): string => $address->postal_code_area->postal_code ?? NULL, $addresses);
    return array_unique($postalCodes);
  }

  /**
   * Get the elastic client.
   *
   * @return \Elastic\Elasticsearch\Client
   *   The elastic client.
   */
  private function getElasticClient(): Client {
    $host = $this->environmentResolver
      ->getActiveEnvironment()
      ->getService(ServiceEnum::ElasticProxy)
      ?->address;

    return ElasticClientBuilder::create()
      ->setHosts([$host])
      ->setHttpClient($this->clientFactory->fromOptions([
        'timeout' => 5,
        'connect_timeout' => 1,
        // Elasticsearch uses a self-signed certificate.
        'verify' => $this->environmentResolver->getActiveEnvironmentName() != 'local',
      ]))
      ->build();
  }

  /**
   * Create the elasticsearch query.
   *
   * @param string|null $parkingZone
   *   The parking zone.
   * @param array<string>|null $postalCodes
   *   The unique postal codes.
   *
   * @return array<mixed>
   *   The query with parameters.
   */
  private function getElasticQuery(?string $parkingZone = NULL, ?array $postalCodes = NULL): array {
    $query = [];
    if ($postalCodes) {
      $query = ['terms' => ['postal_codes' => $postalCodes]];
    }

    if ($parkingZone) {
      $query = ['term' => ['parking_zone_names' => $parkingZone]];
    }

    return [
      'size' => 3,
      'from' => 0,
      'query' => [
        'bool' => [
          'filter' => [
            $query,
          ],
        ],
      ],
    ];
  }

}
