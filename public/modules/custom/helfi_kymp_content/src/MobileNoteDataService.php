<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

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
   * Target projection (WGS84).
   */
  protected Proj $projTarget;

  /**
   * Constructs a new MobileNoteDataService instance.
   */
  public function __construct(
    protected readonly ClientInterface $client,
    protected readonly TypedDataManagerInterface $typedDataManager,
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
   * @return array<int|string, \Drupal\Core\TypedData\ComplexDataInterface>
   *   MobileNote data keyed by ID.
   */
  public function getMobileNoteData(): array {
    $settings = Settings::get('helfi_kymp_mobilenote', []);

    if (empty($settings['wfs_url']) || empty($settings['wfs_username']) || empty($settings['wfs_password'])) {
      $this->logger->warning('MobileNote: Missing API credentials. Cannot fetch data.');
      return [];
    }

    try {
      $features = $this->fetchFromApi($settings);
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

    $data = [];
    foreach ($features as $feature) {
      $item = $this->transformFeature($feature);
      if ($item !== NULL) {
        $id = $item['id'];
        $dataDefinition = $this->typedDataManager->createDataDefinition('mobilenote_data');
        /** @var \Drupal\Core\TypedData\ComplexDataInterface $typedData */
        $typedData = $this->typedDataManager->create($dataDefinition);
        $typedData->setValue($item);
        $data[$id] = $typedData;
      }
    }

    return $data;
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
    $minDate = (new \DateTime())->modify($lookbackOffset)->format('Y-m-d');

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

}
