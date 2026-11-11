<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
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
      $elasticQuery = $this->getElasticQuery(parkingZones: [$parkingZoneName]);
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
    try {
      $client = $this->getElasticClient();
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
      ?->address?->getAddress();

    if (!$host) {
      throw new EnvironmentResolverException();
    }

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
   * Get query for finding mobile notes by postal code or parking zone.
   *
   * Check hdbt vehicle-removal-search useVehicleRemovalQuery.ts.
   *
   * @param string[] $postalCodes
   *   The postal codes.
   * @param string[] $parkingZones
   *   The parking zone names.
   * @param int $size
   *   Maximum number of results.
   * @param int $offset
   *   The offset.
   *
   * @return array<string, mixed>
   *   The query body.
   */
  private function getElasticQuery(
    array $postalCodes = [],
    array $parkingZones = [],
    int $size = 3,
    int $offset = 0,
  ): array {
    // A result matches if it matches any of the selected filters.
    $should = [];

    if ($postalCodes) {
      $should[] = ['terms' => ['postal_codes' => array_values($postalCodes)]];
    }
    if ($parkingZones) {
      $should[] = ['terms' => ['parking_zone_names' => array_values($parkingZones)]];
    }

    // Show removals until a day after valid_to. The index also keeps the ones
    // that ended before that.
    $bool = [
      'filter' => [
        ['range' => ['valid_to' => ['gte' => 'now-1d']]],
      ],
    ];

    if ($should) {
      $bool['should'] = $should;
      $bool['minimum_should_match'] = 1;
    }

    return [
      'size' => $size,
      'from' => $offset,
      'query' => [
        'function_score' => [
          'query' => ['bool' => $bool],
          'functions' => [
            [
              'linear' => [
                'valid_from' => [
                  'origin' => 'now',
                  'scale' => '7d',
                  'decay' => 0.5,
                ],
              ],
            ],
          ],
          'boost_mode' => 'replace',
        ],
      ],
    ];
  }

}
