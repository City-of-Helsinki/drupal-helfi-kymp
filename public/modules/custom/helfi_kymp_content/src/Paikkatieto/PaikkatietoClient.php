<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Paikkatieto;

use Drupal\Core\Site\Settings;
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

  /**
   * Earth radius in meters.
   */
  private const float EARTH_RADIUS = 6371000;

  public function __construct(
    private readonly Settings $settings,
    private readonly ClientInterface $httpClient,
  ) {
  }

  /**
   * Fetches unique street names along a linestring.
   *
   * Queries the API at the midpoint of each segment and returns
   * the deduplicated set of closest street names.
   *
   * We attempt to avoid picking the wrong results at an intersection
   * by choosing midpoints.
   *
   * @param array $coordinates
   *   Array of [lon, lat] coordinate pairs (GeoJSON order).
   * @param int $distance
   *   Search radius in meters for each segment midpoint.
   *
   * @return array
   *   Unique street names (fi and sv) found along the linestring.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  public function fetchStreetsForLineString(array $coordinates, int $distance = 75): array {
    $count = count($coordinates);
    $streets = [];

    for ($i = 0; $i < $count - 1; $i++) {
      $midLat = ($coordinates[$i][1] + $coordinates[$i + 1][1]) / 2;
      $midLon = ($coordinates[$i][0] + $coordinates[$i + 1][0]) / 2;

      $segmentStreets = $this->fetchStreetsByPoint($midLat, $midLon, $distance);
      array_push($streets, ...$segmentStreets);
    }

    return array_values(array_unique($streets));
  }

  /**
   * Fetches street names using the point-radius method.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   * @param int $distance
   *   Distance. We look at street names within this
   *   distance and pick the closest result.
   *
   * @return array
   *   A list of unique street names found within radius.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  public function fetchStreetsByPoint(float $lat, float $lon, int $distance = 75): array {
    $results = $this->makeRequest([
      'lat' => $lat,
      'lon' => $lon,
      'distance' => $distance,
      'limit' => 20,
    ]);

    if (empty($results)) {
      return [];
    }

    // Find the closest result by haversine distance.
    $closest = NULL;
    $minDistance = PHP_FLOAT_MAX;

    foreach ($results as $result) {
      $coords = $result->location->coordinates ?? NULL;
      if (!$coords) {
        continue;
      }
      // API returns [lon, lat] (GeoJSON order).
      $d = self::haversineDistance($lat, $lon, $coords[1], $coords[0]);
      if ($d < $minDistance) {
        $minDistance = $d;
        $closest = $result;
      }
    }

    if (!$closest) {
      return [];
    }

    $streets = [];
    foreach (['fi', 'sv'] as $langcode) {
      if (!empty($closest->street->name->{$langcode})) {
        $streets[] = $closest->street->name->{$langcode};
      }
    }

    return $streets;
  }

  /**
   * Fetches results from the Paikkatietohaku API.
   *
   * @param array $queryParams
   *   Query parameters for the API request.
   * @param int $maxRetries
   *   Maximum number of retries on 502 errors.
   *
   * @return array
   *   The results array from the API response.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  private function makeRequest(array $queryParams, int $maxRetries = 3): array {
    $apiSettings = $this->settings->get('helfi_kymp_mobilenote', []);
    $apiKey = $apiSettings['address_api_key'] ?? NULL;

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

        $data = Utils::jsonDecode($response->getBody()->getContents());

        return $data->results ?? [];
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

  /**
   * Calculate the distance between two coordinates using the Haversine formula.
   */
  private static function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $deltaLat = $lat2 - $lat1;
    $deltaLon = $lon2 - $lon1;

    $a = sin($deltaLat / 2) ** 2
      + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return self::EARTH_RADIUS * $c;
  }

}
