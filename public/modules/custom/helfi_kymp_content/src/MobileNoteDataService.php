<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Utility\Error;
use Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient;
use Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Utils;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Service for fetching MobileNote data from WFS API.
 */
class MobileNoteDataService implements LoggerAwareInterface {

  use LoggerAwareTrait;

  /**
   * Constructs a new MobileNoteDataService instance.
   */
  public function __construct(
    protected ClientInterface $client,
    protected TypedDataManagerInterface $typedDataManager,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected PaikkatietoClient $paikkatietoClient,
    protected GeometryConverter $geometryConverter,
  ) {
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
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  public function fetchNearbyStreets(array $items): void {
    foreach ($items as $item) {
      $geo = $item->get('geometry')->getValue();

      if (!$geo || empty($geo->coordinates)) {
        continue;
      }

      // These calls can fail with an exception when the API is
      // unable to handle too many requests. If that happens,
      // the processing should fail and be re-tried automatically.
      $streets = match ($geo->type ?? '') {
        'linestring' => $this->paikkatietoClient->fetchStreetsForLineString($geo->coordinates),
        'multilinestring' => $this->paikkatietoClient->fetchStreetsForMultiLineString($geo->coordinates),
        default => throw new \InvalidArgumentException(sprintf(
          'MobileNote: cannot fetch nearby streets for geometry type "%s".',
          $geo->type ?? '',
        )),
      };

      $item->set('street_names', $streets);
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
    $settings = $this->configFactory->get('helfi_kymp_content.settings');

    if (
      empty($settings->get('wfs_url')) ||
      empty($settings->get('wfs_username')) ||
      empty($settings->get('wfs_password'))
    ) {
      throw new \InvalidArgumentException('MobileNote: Missing API credentials.');
    }

    try {
      $minDate = (new \DateTime())
        ->setTimestamp($this->time->getRequestTime())
        ->modify($settings->get('sync_lookback_offset') ?? '-30 days');
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

    $response = $this->client->request('GET', $settings->get('wfs_url'), [
      'auth' => [$settings->get('wfs_username'), $settings->get('wfs_password')],
      'query' => [
        'service' => 'WFS',
        'version' => '1.1.0',
        'request' => 'GetFeature',
        'typeName' => 'ppoytakirjaExtranet',
        'outputFormat' => 'application/json',
        'srsName' => 'EPSG:3879',
        'maxFeatures' => 10000,
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

    try {
      $item = [
        'id' => $featureId,
        'address' => $properties['osoite'] ?? '',
        'reason' => $properties['merkinSyy']['value'] ?? '',
        'valid_from' => $this->dateToTimestamp($properties['voimassaoloAlku'] ?? NULL),
        // We only get date string from mobilenote. We
        // add 24 hours to get the end timestamp.
        'valid_to' => $this->dateToTimestamp($properties['voimassaoloLoppu'] ?? NULL) + 86400,
        'time_range' => $properties['kello'] ?? '',
        'created_at' => $this->dateTimeToTimestamp($properties['luontipvm'] ?? NULL),
        'updated_at' => $this->dateTimeToTimestamp($properties['paivityspvm'] ?? NULL),
        'address_info' => $properties['osoitteenlisatieto'] ?? '',
        'sign_type' => $properties['merkinLaatu']['value'] ?? '',
        'additional_text' => $properties['lisakilvenTeksti'] ?? '',
        'notes' => $properties['huomautukset'] ?? '',
        'phone' => $properties['puhelinnumero'] ?? '',
      ];
    }
    catch (\DateException $e) {
      throw new \InvalidArgumentException("MobileNote: Invalid date", previous: $e);
    }

    // Convert geometry to GeoJSON object.
    if (!empty($geometry['coordinates'])) {
      $item['geometry'] = $this->geometryConverter->convertHelsinkiToWgs84($geometry);
      $item['map_url'] = KarttaUtility::buildUrl($geometry);
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
   *
   * @throws \DateException
   */
  protected function dateToTimestamp(?string $dateString): ?int {
    if (empty($dateString)) {
      throw new \DateMalformedStringException('Empty date string');
    }

    return (new \DateTime($dateString, new \DateTimeZone('Europe/Helsinki')))->getTimestamp();
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
      return (new \DateTime($dateTimeString, new \DateTimeZone('Europe/Helsinki')))->getTimestamp();
    }
    catch (\DateException) {
      return NULL;
    }
  }

}
