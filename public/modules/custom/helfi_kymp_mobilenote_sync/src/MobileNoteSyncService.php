<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_mobilenote_sync;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use proj4php\Point;
use proj4php\Proj;
use proj4php\Proj4php;

/**
 * Service for syncing MobileNote data to Drupal nodes.
 */
class MobileNoteSyncService {

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
   * Constructs a MobileNoteSyncService object.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelInterface $logger,
  ) {
    // EPSG:3879 is Helsinki local CRS (ETRS-GK25FIN) - must be defined manually.
    $this->proj4 = new Proj4php();
    $this->proj4->addDef(
      'EPSG:3879',
      '+proj=tmerc +lat_0=0 +lon_0=25 +k=1 +x_0=25500000 +y_0=0 +ellps=GRS80 +towgs84=0,0,0,0,0,0,0 +units=m +no_defs'
    );
    $this->projSource = new Proj('EPSG:3879', $this->proj4);
    $this->projTarget = new Proj('EPSG:4326', $this->proj4);
  }

  /**
   * Runs the sync process.
   */
  public function sync(): void {
    $settings = Settings::get('helfi_kymp_mobilenote_sync');

    if (empty($settings['wfs_url']) || empty($settings['wfs_username']) || empty($settings['wfs_password'])) {
      $this->logger->warning('MobileNote sync: Missing API credentials. Skipping sync.');
      return;
    }

    try {
      $features = $this->fetchFromApi($settings);
      $this->logger->info('MobileNote sync: Fetched @count features from API.', [
        '@count' => count($features),
      ]);

      $created = 0;
      $updated = 0;
      $skipped = 0;

      foreach ($features as $feature) {
        $result = $this->processFeature($feature);
        switch ($result) {
          case 'created':
            $created++;
            break;

          case 'updated':
            $updated++;
            break;

          default:
            $skipped++;
        }
      }

      $this->logger->info('MobileNote sync completed: @created created, @updated updated, @skipped skipped.', [
        '@created' => $created,
        '@updated' => $updated,
        '@skipped' => $skipped,
      ]);

      // Cleanup old expired items.
      $deleted = $this->cleanupExpiredItems($settings);
      if ($deleted > 0) {
        $this->logger->info('MobileNote cleanup: Deleted @count expired items.', [
          '@count' => $deleted,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('MobileNote sync failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Removes expired items based on voimassaoloLoppu + offset.
   *
   * @param array $settings
   *   The settings array.
   *
   * @return int
   *   Number of deleted items.
   */
  protected function cleanupExpiredItems(array $settings): int {
    $removalOffset = $settings['sync_removal_offset'] ?? '+30 days';

    // '+30 days' means keep for 30 days after expiry,
    // so delete where voimassaoloLoppu < (today - 30 days).
    $invertedOffset = str_starts_with($removalOffset, '+')
      ? '-' . substr($removalOffset, 1)
      : '+' . substr($removalOffset, 1);

    $cutoffDate = (new \DateTime())->modify($invertedOffset)->format('Y-m-d');

    // Find items where voimassaoloLoppu < cutoff date.
    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'mobilenote_item')
      ->condition('field_mn_valid_to', $cutoffDate, '<')
      ->accessCheck(FALSE);

    $nids = $query->execute();

    if (empty($nids)) {
      return 0;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $nodes = $storage->loadMultiple($nids);
    $storage->delete($nodes);

    return count($nids);
  }

  /**
   * Fetches features from the MobileNote WFS API.
   *
   * @param array $settings
   *   The API settings.
   *
   * @return array
   *   Array of feature objects.
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

    $response = $this->httpClient->get($settings['wfs_url'], [
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
   * Processes a single feature from the API.
   *
   * @param array $feature
   *   The feature data from the API.
   *
   * @return string
   *   Result: 'created', 'updated', or 'skipped'.
   */
  protected function processFeature(array $feature): string {
    $featureId = $feature['id'] ?? NULL;
    if (!$featureId) {
      return 'skipped';
    }

    $properties = $feature['properties'] ?? [];
    $apiUpdatedAt = $properties['paivityspvm'] ?? NULL;

    // Check if feature already exists.
    $existingId = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'mobilenote_item')
      ->condition('field_mn_feature_id', $featureId)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    $existingId = reset($existingId) ?: NULL;

    if (!$existingId) {
      // Create new node.
      $this->createNode($feature);
      return 'created';
    }

    // Check if update is needed.
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load($existingId);
    if ($node && $apiUpdatedAt) {
      $storedUpdatedAt = $node->get('field_mn_updated_at')->value;
      $needsUpdate = empty($storedUpdatedAt) || new \DateTime($apiUpdatedAt) > new \DateTime($storedUpdatedAt);
      if ($needsUpdate) {
        $this->updateNode($node, $feature);
        return 'updated';
      }
    }

    return 'skipped';
  }

  /**
   * Creates a new node from feature data.
   *
   * @param array $feature
   *   The feature data from the API.
   */
  protected function createNode(array $feature): void {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'mobilenote_item',
      'title' => $feature['properties']['osoite'] ?? 'Unknown address',
      'status' => NodeInterface::PUBLISHED,
    ]);

    $this->setNodeFields($node, $feature);
    $node->save();
  }

  /**
   * Updates an existing node with feature data.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to update.
   * @param array $feature
   *   The feature data from the API.
   */
  protected function updateNode(NodeInterface $node, array $feature): void {
    $properties = $feature['properties'] ?? [];
    $node->setTitle($properties['osoite']);
    $this->setNodeFields($node, $feature);
    $node->save();
  }

  /**
   * Sets field values on a node from feature data.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to update.
   * @param array $feature
   *   The feature data from the API.
   */
  protected function setNodeFields(NodeInterface $node, array $feature): void {
    $properties = $feature['properties'] ?? [];
    $geometry = $feature['geometry'] ?? [];

    // Essential fields.
    $node->set('field_mn_feature_id', $feature['id'] ?? '');
    $node->set('field_mn_address', $properties['osoite'] ?? '');
    $node->set('field_mn_reason', $properties['merkinSyy']['value'] ?? '');
    $node->set('field_mn_valid_from', $properties['voimassaoloAlku'] ?? NULL);
    $node->set('field_mn_valid_to', $properties['voimassaoloLoppu'] ?? NULL);
    $node->set('field_mn_time_range', $properties['kello'] ?? '');
    $node->set('field_mn_created_at', $this->formatDateTime($properties['luontipvm'] ?? NULL));
    $node->set('field_mn_updated_at', $this->formatDateTime($properties['paivityspvm'] ?? NULL));

    // Convert and store geometry as GeoJSON for geo_shape field.
    if (!empty($geometry['coordinates'])) {
      $convertedGeometry = $this->convertGeometry($geometry);
      // geo_shape field stores GeoJSON string in 'value' property.
      $node->set('field_mn_geometry', ['value' => json_encode($convertedGeometry, JSON_UNESCAPED_UNICODE)]);
    }

    // Additional fields.
    $node->set('field_mn_address_info', $properties['osoitteenlisatieto'] ?? '');
    $node->set('field_mn_sign_type', $properties['merkinLaatu']['value'] ?? '');
    $node->set('field_mn_additional_text', $properties['lisakilvenTeksti'] ?? '');
    $node->set('field_mn_notes', $properties['huomautukset'] ?? '');
    $node->set('field_mn_phone', $properties['puhelinnumero'] ?? '');

    // Debug field - store raw JSON.
    $node->set('field_mn_raw_json', json_encode($feature, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Formats a datetime string from API to Drupal.
   *
   * @param string|null $dateTimeString
   *   The datetime string (ISO 8601 format).
   *
   * @return string|null
   *   The formatted datetime or NULL.
   */
  protected function formatDateTime(?string $dateTimeString): ?string {
    if (empty($dateTimeString)) {
      return NULL;
    }
    // Convert ISO 8601 to Drupal format (Y-m-d\TH:i:s).
    try {
      $dt = new \DateTime($dateTimeString);
      return $dt->format('Y-m-d\TH:i:s');
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
   * @return array
   *   The converted GeoJSON geometry.
   */
  protected function convertGeometry(array $geometry): array {
    $convertedCoordinates = [];

    foreach ($geometry['coordinates'] as $coord) {
      $point = new Point($coord[0], $coord[1], $this->projSource);
      $transformed = $this->proj4->transform($this->projTarget, $point);
      // GeoJSON uses [longitude, latitude] order.
      $convertedCoordinates[] = [$transformed->x, $transformed->y];
    }

    return [
      'type' => $geometry['type'] ?? 'LineString',
      'coordinates' => $convertedCoordinates,
    ];
  }

}
