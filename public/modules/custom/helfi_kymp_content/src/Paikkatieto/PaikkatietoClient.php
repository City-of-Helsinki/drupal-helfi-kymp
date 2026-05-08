<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Paikkatieto;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Utils;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Client for paikkatieto API.
 *
 * @see https://helsinkisolutionoffice.atlassian.net/wiki/spaces/KAN/pages/7614431294/Paikkatietohaku+-+palvelu
 */
class PaikkatietoClient implements LoggerAwareInterface {

  use LoggerAwareTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
  ) {
  }

  /**
   * Fetches unique street names along a linestring.
   *
   * Queries the API at the midpoint of each segment and returns
   * the deduplicated set of street names found within the configured
   * search radius.
   *
   * @param array<array{float, float}> $coordinates
   *   Array of [lon, lat] coordinate pairs (GeoJSON order).
   *
   * @return array<string>
   *   Unique street names (fi and sv) found along the linestring.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  public function fetchStreetsForLineString(array $coordinates): array {
    $count = count($coordinates);
    $streets = [];

    for ($i = 0; $i < $count - 1; $i++) {
      $midLat = ($coordinates[$i][1] + $coordinates[$i + 1][1]) / 2;
      $midLon = ($coordinates[$i][0] + $coordinates[$i + 1][0]) / 2;

      $segmentStreets = $this->fetchStreetsByPoint($midLat, $midLon);
      array_push($streets, ...$segmentStreets);
    }

    return array_values(array_unique($streets));
  }

  /**
   * Fetches unique street names for a GeoJSON LineString or MultiLineString.
   *
   * Dispatches by geometry type so callers don't have to inspect the
   * geometry shape themselves.
   *
   * @param object $geometry
   *   The GeoJSON geometry as an stdClass with `->type` (lowercased) and
   *   `->coordinates` in WGS84 [lon, lat] order.
   *
   * @return array<string>
   *   Unique street names found along the geometry.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   *   When the geometry type is not LineString or MultiLineString, or
   *   when the upstream API call fails.
   * @throws \InvalidArgumentException
   *   When the API key is missing.
   */
  public function fetchStreetsForGeometry(object $geometry): array {
    return match ($geometry->type ?? '') {
      'linestring' => $this->fetchStreetsForLineString($geometry->coordinates ?? []),
      'multilinestring' => $this->fetchStreetsForMultiLineString($geometry->coordinates ?? []),
      default => throw new Exception(sprintf(
        'Paikkatieto: cannot fetch nearby streets for geometry type "%s".',
        $geometry->type ?? '',
      )),
    };
  }

  /**
   * Fetches unique street names along a multi-linestring.
   *
   * Calls fetchStreetsForLineString() for each component LineString and
   * returns the deduplicated union.
   *
   * @param array<array<array{float, float}>> $multiCoordinates
   *   The MultiLineString coordinate array (a list of LineStrings, each
   *   itself a list of [lon, lat] pairs in GeoJSON order).
   *
   * @return array<string>
   *   Unique street names (fi and sv) found along all components.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  public function fetchStreetsForMultiLineString(array $multiCoordinates): array {
    $streets = [];
    foreach ($multiCoordinates as $segment) {
      array_push($streets, ...$this->fetchStreetsForLineString($segment));
    }
    return array_values(array_unique($streets));
  }

  /**
   * Fetches street names using the point-radius method.
   *
   * Returns all unique street names found within the configured search
   * radius, not just the closest. The search distance and result limit
   * are read from 'helfi_kymp_content.settings' config
   * (address_search_distance, address_search_limit) with defaults of
   * 75 meters and 20 results.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   *
   * @return array<string>
   *   A list of unique street names found within radius.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  public function fetchStreetsByPoint(float $lat, float $lon): array {
    $config = $this->configFactory->get('helfi_kymp_content.settings');
    $distance = $config->get('address_search_distance') ?: 75;
    $limit = $config->get('address_search_limit') ?: 20;

    $data = $this->makeRequest([
      'lat' => $lat,
      'lon' => $lon,
      'distance' => $distance,
      'limit' => $limit,
    ]);
    $results = $data->results ?? [];

    if (empty($results)) {
      return [];
    }

    $streets = [];
    foreach ($results as $result) {
      foreach (['fi', 'sv'] as $langcode) {
        if (!empty($result->street->name->{$langcode})) {
          $streets[] = $result->street->name->{$langcode};
        }
      }
    }

    return array_values(array_unique($streets));
  }

  /**
   * Fetches unique street names from a single page of the API.
   *
   * @param int $page
   *   The 1-based page number.
   * @param int $pageSize
   *   Results per page.
   *
   * @return array<int, array{name: string, language: string}>|null
   *   List of street names with their language, or NULL past the last page.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  public function fetchStreetNamesByPage(int $page = 1, int $pageSize = 500): ?array {
    try {
      $data = $this->makeRequest([
        'municipality' => 'Helsinki',
        'page_size' => $pageSize,
        'page' => $page,
      ]);
    }
    catch (Exception $e) {
      // API returns 404 {"detail":"Epäkelpo sivu."} past the last page.
      $previous = $e->getPrevious();
      if ($previous instanceof RequestException && $previous->getResponse()?->getStatusCode() === 404) {
        return NULL;
      }

      throw $e;
    }

    $results = $data->results ?? [];
    $streets = [];

    foreach ($results as $result) {
      foreach (['fi', 'sv', 'en'] as $langcode) {
        $name = $result->street->name->{$langcode} ?? NULL;
        if (!empty($name)) {
          $streets[] = ['name' => $name, 'language' => $langcode];
        }
      }
    }

    $this->logger?->info('Paikkatieto street names: fetched page @page (@count results).', [
      '@page' => $page,
      '@count' => count($results),
    ]);

    return $streets;
  }

  /**
   * Fetches a response from the Paikkatietohaku API.
   *
   * @param array<string, mixed> $queryParams
   *   Query parameters for the API request.
   * @param int $maxRetries
   *   Maximum number of retries on 502 errors.
   *
   * @return object
   *   The full decoded JSON response.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  private function makeRequest(array $queryParams, int $maxRetries = 3): object {
    $config = $this->configFactory->get('helfi_kymp_content.settings');
    $apiKey = $config->get('address_api_key');

    if (empty($apiKey)) {
      throw new \InvalidArgumentException('Paikkatietoapi: Missing API key.');
    }

    $lastException = NULL;

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
      if ($attempt > 0) {
        // Exponential backoff: 1s, 2s, 4s.
        sleep(2 ** ($attempt - 1));
      }

      try {
        $response = $this->httpClient->request('GET', 'https://paikkatietohaku.api.hel.fi/v1/address/', [
          'headers' => ['Api-Key' => $apiKey],
          'query' => $queryParams,
          'timeout' => 60,
        ]);

        /** @var object $data */
        $data = Utils::jsonDecode($response->getBody()->getContents());
        return $data;
      }
      catch (GuzzleException $e) {
        $lastException = $e;
        $is502 = $e instanceof RequestException && $e->getResponse()?->getStatusCode() === 502;

        // Only retry on 502 (rate limiting).
        if (!$is502) {
          throw new Exception($e->getMessage(), previous: $e);
        }

        $this->logger?->info('Paikkatietoapi returned 502, retry @attempt of @max.', [
          '@attempt' => $attempt + 1,
          '@max' => $maxRetries,
        ]);
      }
    }

    throw new Exception($lastException->getMessage(), previous: $lastException);
  }

}
