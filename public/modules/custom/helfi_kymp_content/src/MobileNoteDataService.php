<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use proj4php\Point;
use proj4php\Proj;
use proj4php\Proj4php;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service for fetching MobileNote data from WFS API.
 */
class MobileNoteDataService {

  /**
   * Proj4php instance for coordinate conversion.
   */
  protected Proj4php $proj4;

  /**
   * Source projection (EPSG:3879).
   */
  protected Proj $projSource;

  /**
   * The target projection (WGS84).
   */
  protected Proj $projTarget;

  public const METHOD_BBOX = 'BBOX';
  public const METHOD_POINT = 'POINT';

  /**
   * The street query mode to use.
   */
  private const STREET_QUERY_MODE = self::METHOD_POINT;

  /**
   * Constructs a new MobileNoteDataService instance.
   */
  public function __construct(
    protected readonly ClientInterface $client,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly TimeInterface $time,
    protected readonly Settings $settings,
    #[Autowire(service: 'logger.channel.helfi_kymp_content')]
    protected readonly LoggerInterface $logger,
  ) {
    // EPSG:3879 is Helsinki local CRS (ETRS-GK25FIN).
    $this->proj4 = new Proj4php();
    $this->proj4->addDef(
      'EPSG:3879',
      '+proj=tmerc +lat_0=0 +lon_0=25 +k=1 +x_0=25500000 +y_0=0 +ellps=GRS80 +towgs84=0,0,0,0,0,0,0 +units=m +no_defs'
    );
    $this->projSource = new Proj('EPSG:3879', $this->proj4);
    $this->projTarget = new Proj('EPSG:4326', $this->proj4);
  }

  /**
   * Gets MobileNote data.
   *
   * @param bool $fetchNearbyStreetData
   *   Whether to fetch street name data.
   *
   * @return array<int|string, \Drupal\Core\TypedData\ComplexDataInterface>
   *   An array of MobileNote data items, keyed by ID.
   */
  public function getMobileNoteData(bool $fetchNearbyStreetData = FALSE): array {
    static $cache = NULL;

    if ($cache !== NULL && !$fetchNearbyStreetData) {
      return $cache;
    }

    $apiSettings = $this->settings->get('helfi_kymp_mobilenote', []);

    if (empty($apiSettings['wfs_url']) || empty($apiSettings['wfs_username']) || empty($apiSettings['wfs_password'])) {
      $this->logger->info('MobileNote: Missing API credentials. Cannot fetch data.');
      $cache = [];
      return $cache;
    }

    try {
      $features = $this->fetchFromApi($apiSettings);
      $this->logger->info('MobileNote: Fetched @count features from API.', [
        '@count' => count($features),
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('MobileNote: Error fetching data: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    $items = [];
    foreach ($features as $feature) {
      $item = $this->transformFeature($feature);
      if ($item !== NULL) {
        $id = $item['id'];
        $dataDefinition = $this->typedDataManager->createDataDefinition('mobilenote_data');
        /** @var \Drupal\Core\TypedData\ComplexDataInterface $typedData */
        $typedData = $this->typedDataManager->create($dataDefinition);
        $typedData->setValue($item);
        $items[$id] = $typedData;
      }
    }

    // Cache the base data (without streets) for subsequent calls.
    if (!$fetchNearbyStreetData) {
      $cache = $items;
    }

    if ($fetchNearbyStreetData && !empty($items)) {
      $this->fetchNearbyStreets($items);
    }

    return $items;
  }

  /**
   * Fetches nearby street names calling Address API.
   *
   * @param \Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData[] $items
   *   The items to fetch street names for.
   */
  public function fetchNearbyStreets(array $items): void {
    $apiSettings = $this->settings->get('helfi_kymp_mobilenote', []);
    $apiKey = $apiSettings['address_api_key'] ?? NULL;

    if (empty($apiKey)) {
      $this->logger->warning('Paikkatietoapi: Missing API key.');
      return;
    }

    foreach ($items as $item) {
      $hasMethod = method_exists($item, 'get');
      $geo = ($hasMethod) ? $item->get('geometry')->getValue() : NULL;

      // Check if item has 'geometry' property (safer than instanceof).
      if ($hasMethod && $geo) {
        if (self::STREET_QUERY_MODE === self::METHOD_POINT) {
          $result = $this->fetchStreetsByPoint($geo, $apiKey);
        }
        else {
          $result = $this->fetchStreetsByBbox($geo, $apiKey);
        }
        $item->set('street_names', $result);
      }
    }
  }

  /**
   * Fetches features from the MobileNote WFS API.
   *
   * @param array $settings
   *   The API settings.
   *
   * @return array
   *   Array of feature objects.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function fetchFromApi(array $settings): array {

    $lookbackOffset = $settings['sync_lookback_offset'] ?? '-30 days';
    // Use request time for consistent "now" context.
    $currentDate = \DateTime::createFromFormat('U', (string) $this->time->getRequestTime());
    $minDate = $currentDate->modify($lookbackOffset)->format('Y-m-d');

    $filterXml = <<<XML
<Filter xmlns="http://www.opengis.net/ogc" xmlns:gml="http://www.opengis.net/gml">
  <PropertyIsGreaterThanOrEqualTo>
    <PropertyName>voimassaoloAlku</PropertyName>
    <Literal>{$minDate}</Literal>
  </PropertyIsGreaterThanOrEqualTo>
</Filter>
XML;

    $response = $this->client->request('GET', $settings['wfs_url'], [
      'auth' => [$settings['wfs_username'], $settings['wfs_password']],
      'query' => [
        'service' => 'WFS',
        'version' => '1.1.0',
        'request' => 'GetFeature',
        'typeName' => 'ppoytakirjaExtranet',
        'outputFormat' => 'application/json',
        'srsName' => 'EPSG:3879',
        'maxFeatures' => 1000,
        'filter' => preg_replace('/\s+/', ' ', trim($filterXml)),
      ],
      'timeout' => 30,
    ]);

    $data = json_decode($response->getBody()->getContents(), TRUE);
    return $data['features'] ?? [];
  }

  /**
   * Transforms an API feature to typed data array.
   *
   * @param array $feature
   *   The feature data from the API.
   *
   * @return array|null
   *   The transformed data or NULL if invalid.
   */
  protected function transformFeature(array $feature): ?array {
    $featureId = $feature['id'] ?? NULL;
    if (!$featureId) {
      return NULL;
    }

    $properties = $feature['properties'] ?? [];
    $geometry = $feature['geometry'] ?? [];

    $item = [
      'id' => $featureId,
      'address' => $properties['osoite'] ?? '',
      'reason' => $properties['merkinSyy']['value'] ?? '',
      'valid_from' => $this->dateToTimestamp($properties['voimassaoloAlku'] ?? NULL),
      'valid_to' => $this->dateToTimestamp($properties['voimassaoloLoppu'] ?? NULL),
      'time_range' => $properties['kello'] ?? '',
      'created_at' => $this->dateTimeToTimestamp($properties['luontipvm'] ?? NULL),
      'updated_at' => $this->dateTimeToTimestamp($properties['paivityspvm'] ?? NULL),
      'address_info' => $properties['osoitteenlisatieto'] ?? '',
      'sign_type' => $properties['merkinLaatu']['value'] ?? '',
      'additional_text' => $properties['lisakilvenTeksti'] ?? '',
      'notes' => $properties['huomautukset'] ?? '',
      'phone' => $properties['puhelinnumero'] ?? '',
    ];

    // Convert geometry to GeoJSON object.
    if (!empty($geometry['coordinates'])) {
      $item['geometry'] = $this->convertGeometry($geometry);
    }

    return $item;
  }

  /**
   * Converts a date string to timestamp.
   *
   * @param string|null $dateString
   *   The date string (Y-m-d format).
   *
   * @return int|null
   *   The timestamp or NULL.
   */
  protected function dateToTimestamp(?string $dateString): ?int {
    if (empty($dateString)) {
      return NULL;
    }
    try {
      $dt = new \DateTime($dateString);
      return $dt->getTimestamp();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Converts a datetime string to timestamp.
   *
   * @param string|null $dateTimeString
   *   The datetime string (ISO 8601 format).
   *
   * @return int|null
   *   The timestamp or NULL.
   */
  protected function dateTimeToTimestamp(?string $dateTimeString): ?int {
    if (empty($dateTimeString)) {
      return NULL;
    }
    try {
      $dt = new \DateTime($dateTimeString);
      return $dt->getTimestamp();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Converts geometry coordinates from EPSG:3879 to WGS84.
   *
   * @param array $geometry
   *   The geometry object from the API.
   *
   * @return object
   *   The converted GeoJSON geometry as stdClass.
   */
  protected function convertGeometry(array $geometry): object {
    $convertedCoordinates = [];

    foreach ($geometry['coordinates'] as $coord) {
      $point = new Point($coord[0], $coord[1], $this->projSource);
      $transformed = $this->proj4->transform($this->projTarget, $point);
      // GeoJSON uses [longitude, latitude] order.
      $convertedCoordinates[] = [$transformed->x, $transformed->y];
    }

    return (object) [
      'type' => strtolower($geometry['type'] ?? 'linestring'),
      'coordinates' => $convertedCoordinates,
    ];
  }

  /**
   * Fetches street names using the bounding box method.
   *
   * @param object $geometry
   *   The GeoJSON geometry object.
   * @param string $apiKey
   *   The Address API key.
   *
   * @return array
   *   A list of unique street names found within the bounding box.
   */
  protected function fetchStreetsByBbox(object $geometry, string $apiKey): array {
    if (empty($geometry->coordinates)) {
      return [];
    }

    // Calculate Bounding Box (minX, minY, maxX, maxY).
    $lons = array_column($geometry->coordinates, 0);
    $lats = array_column($geometry->coordinates, 1);

    // Add buffer (approx 20m = 0.0002 deg) to ensure results for lines.
    $buffer = 0.0002;
    $minX = min($lons) - $buffer;
    $maxX = max($lons) + $buffer;
    $minY = min($lats) - $buffer;
    $maxY = max($lats) + $buffer;

    return $this->fetchStreetsFromApi([
      'bbox' => implode(',', [$minX, $minY, $maxX, $maxY]),
      'limit' => 20,
    ], $apiKey);
  }

  /**
   * Fetches street names using the point-radius method.
   *
   * @param object $geometry
   *   The GeoJSON geometry object.
   * @param string $apiKey
   *   The Address API key.
   *
   * @return array
   *   A list of unique street names found within radius.
   */
  protected function fetchStreetsByPoint(object $geometry, string $apiKey): array {
    if (empty($geometry->coordinates)) {
      return [];
    }

    // Calculate Centroid.
    $lons = array_column($geometry->coordinates, 0);
    $lats = array_column($geometry->coordinates, 1);
    $count = count($geometry->coordinates);

    if ($count === 0) {
      return [];
    }

    return $this->fetchStreetsFromApi([
      'lat' => array_sum($lats) / $count,
      'lon' => array_sum($lons) / $count,
      'distance' => 20,
      'limit' => 20,
    ], $apiKey);
  }

  /**
   * Fetches street names from the Paikkatietohaku API.
   *
   * @param array $queryParams
   *   Query parameters for the API request.
   * @param string $apiKey
   *   The Address API key.
   *
   * @return array
   *   A list of unique street names.
   */
  protected function fetchStreetsFromApi(array $queryParams, string $apiKey): array {
    try {
      $response = $this->client->request('GET', 'https://paikkatietohaku.api.hel.fi/v1/address/', [
        'headers' => ['Api-Key' => $apiKey],
        'query' => $queryParams,
        'timeout' => 60,
      ]);

      // Throttle requests to prevent API rate limiting.
      sleep(1);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      $streets = [];

      foreach ($data['results'] ?? [] as $result) {
        if (!empty($result['street']['name']['fi'])) {
          $streets[] = $result['street']['name']['fi'];
        }
      }

      return array_values(array_unique($streets));
    }
    catch (\Exception $e) {
      $this->logger->error('Paikkatietoapi failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
