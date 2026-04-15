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
   * @param array $coordinates
   *   Array of [lon, lat] coordinate pairs (GeoJSON order).
   *
   * @return array
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
   * @return array
   *   A list of unique street names found within radius.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  public function fetchStreetsByPoint(float $lat, float $lon): array {
    $config = $this->configFactory->get('helfi_kymp_content.settings');
    $distance = $config->get('address_search_distance') ?: 75;
    $limit = $config->get('address_search_limit') ?: 20;

    $results = $this->makeRequest([
      'lat' => $lat,
      'lon' => $lon,
      'distance' => $distance,
      'limit' => $limit,
    ]);

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

}
