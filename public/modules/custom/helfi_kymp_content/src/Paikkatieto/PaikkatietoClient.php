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

  public function __construct(
    private readonly Settings $settings,
    private readonly ClientInterface $httpClient,
  ) {
  }

  /**
   * Fetches street names using the point-radius method.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   * @param int $distance
   *   Distance.
   *
   * @return array
   *   A list of unique street names found within radius.
   *
   * @throws \Drupal\helfi_kymp_content\Paikatieto\Exception
   * @throws \InvalidArgumentException
   */
  public function fetchStreetsByPoint(float $lat, float $lon, int $distance = 20): array {
    return $this->makeRequest([
      'lat' => $lat,
      'lon' => $lon,
      'distance' => $distance,
      'limit' => 20,
    ]);
  }

  /**
   * Fetches street names from the Paikkatietohaku API.
   *
   * @param array $queryParams
   *   Query parameters for the API request.
   *
   * @throws \Drupal\helfi_kymp_content\Paikkatieto\Exception
   * @throws \InvalidArgumentException
   */
  private function makeRequest(array $queryParams): array {
    $apiSettings = $this->settings->get('helfi_kymp_mobilenote', []);
    $apiKey = $apiSettings['address_api_key'] ?? NULL;

    if (empty($apiKey)) {
      throw new \InvalidArgumentException('Paikkatietoapi: Missing API key.');
    }

    try {
      $response = $this->httpClient->request('GET', 'https://paikkatietohaku.api.hel.fi/v1/address/', [
        'headers' => ['Api-Key' => $apiKey],
        'query' => $queryParams,
        'timeout' => 60,
      ]);

      $data = Utils::jsonDecode($response->getBody()->getContents(), TRUE);
      $streets = [];

      foreach ($data['results'] ?? [] as $result) {
        if (!empty($result['street']['name']['fi'])) {
          $streets[] = $result['street']['name']['fi'];
        }
        if (!empty($result['street']['name']['sv'])) {
          $streets[] = $result['street']['name']['sv'];
        }
      }

      return array_values(array_unique($streets));
    }
    catch (GuzzleException $e) {
      // The API is known to fail with 502 when too many
      // requests are made. The consumers should be
      // configured to retry requests later if this fails.
      if (!$e instanceof RequestException || $e->getResponse()?->getStatusCode() !== 502) {
        $this->logger?->error('Paikkatietoapi failed: @message', [
          '@message' => $e->getMessage(),
        ]);
      }

      throw new Exception($e->getMessage(), previous: $e);
    }
  }

}
