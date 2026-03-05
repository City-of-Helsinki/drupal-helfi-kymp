<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Utility\Error;
use Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient;
use Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Utils;
use proj4php\Point;
use proj4php\Proj;
use proj4php\Proj4php;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Service for fetching MobileNote data from WFS API.
 */
class MobileNoteDataService implements LoggerAwareInterface {

  use LoggerAwareTrait;

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

  /**
   * Constructs a new MobileNoteDataService instance.
   */
  public function __construct(
    protected ClientInterface $client,
    protected TypedDataManagerInterface $typedDataManager,
    protected TimeInterface $time,
    protected Settings $settings,
    protected PaikkatietoClient $paikkatietoClient,
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
   * @return array<string, \Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData>
   *   An array of MobileNote data items, keyed by ID.
   */
  public function getMobileNoteData(): array {
    static $cache = NULL;

    if ($cache !== NULL) {
      return $cache;
    }

    try {
      $items = $this->fetchFromApi();
    }
    catch (GuzzleException | \InvalidArgumentException $e) {
      if ($this->logger) {
        Error::logException($this->logger, $e);
      }

      return [];
    }

    $this->logger?->info('MobileNote: Fetched @count features from API.', [
      '@count' => count($items),
    ]);

    return $cache = $items;
  }

  /**
   * Fetches nearby street names calling Address API.
   *
   * @param \Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData[] $items
   *   The items to fetch street names for.
   */
  public function fetchNearbyStreets(array $items): void {
    foreach ($items as $item) {
      $geo = $item->get('geometry')->getValue();

      if (!$geo) {
        continue;
      }

      if (empty($geo->coordinates)) {
        continue;
      }

      if (!isset($geo->type) || $geo->type !== 'linestring') {
        $this->logger?->warning('Skipping item with unknown geometry type @type.', [
          '@type' => $geo->type ?? '',
        ]);
      }

      // This can fail with an exception when the API is
      // unable to handle too many requests. If that happens,
      // the processing should fail and be re-tried automatically.
      $result = $this->paikkatietoClient->fetchStreetsForLineString($geo->coordinates);

      $item->set('street_names', $result);
    }
  }

  /**
   * Fetches features from the MobileNote WFS API.
   *
   * @return array<string, \Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData>
   *   Array of feature objects.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \InvalidArgumentException
   */
  protected function fetchFromApi(): array {
    $settings = $this->settings->get('helfi_kymp_mobilenote', []);

    if (
      empty($settings['wfs_url']) ||
      empty($settings['wfs_username']) ||
      empty($settings['wfs_password'])
    ) {
      throw new \InvalidArgumentException('MobileNote: Missing API credentials.');
    }

    try {
      $minDate = (new \DateTime())
        ->setTimestamp($this->time->getRequestTime())
        ->modify($settings['sync_lookback_offset'] ?? '-30 days');
    }
    catch (\DateMalformedStringException $e) {
      throw new \InvalidArgumentException($e->getMessage(), previous: $e);
    }

    $minDate = $minDate->format('Y-m-d');

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

    $data = Utils::jsonDecode($response->getBody()->getContents(), TRUE);

    $items = [];
    foreach ($data['features'] ?? [] as $feature) {
      $item = $this->transformFeature($feature);

      try {
        $items[$item->get('id')->getString()] = $item;
      }
      catch (MissingDataException $e) {
        throw new \InvalidArgumentException('MobileNote: Missing feature ID.', previous: $e);
      }
    }

    return $items;
  }

  /**
   * Transforms an API feature to a typed data item.
   *
   * @param array $feature
   *   The feature data from the API.
   */
  protected function transformFeature(array $feature): MobileNoteData {
    $featureId = $feature['id'] ?? NULL;
    if (!$featureId) {
      throw new \InvalidArgumentException('MobileNote: Missing feature ID.');
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

    $dataDefinition = $this->typedDataManager->createDataDefinition('mobilenote_data');

    /** @var \Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData $typedData */
    $typedData = $this->typedDataManager->create($dataDefinition);
    $typedData->setValue($item);

    return $typedData;
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
      return (new \DateTime($dateString))->getTimestamp();
    }
    catch (\DateMalformedStringException) {
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
      return (new \DateTime($dateTimeString))->getTimestamp();
    }
    catch (\DateMalformedStringException) {
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
