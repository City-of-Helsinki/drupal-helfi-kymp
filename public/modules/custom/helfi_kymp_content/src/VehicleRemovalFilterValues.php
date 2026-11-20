<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Utility\Error;
use Drupal\helfi_api_base\ServiceMap\ResidentParkingZoneServiceInterface;
use Drupal\helfi_api_base\ServiceMap\ServiceMapInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides the filter values of the vehicle removal search.
 */
class VehicleRemovalFilterValues {

  private const string POSTAL_CODES_CID = 'helfi_kymp_content:vehicle_removal_postal_codes';
  private const string PARKING_ZONES_CID = 'helfi_kymp_content:vehicle_removal_parking_zones';
  private const int CACHE_LIFETIME = 86400;

  public function __construct(
    private readonly ServiceMapInterface $serviceMap,
    private readonly ResidentParkingZoneServiceInterface $parkingZoneService,
    #[Autowire(service: 'cache.default')]
    private readonly CacheBackendInterface $cache,
    #[Autowire(service: 'logger.channel.helfi_kymp_content')]
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Gets the postal codes of Helsinki.
   *
   * @return list<string>|null
   *   Unique postal codes in order, or NULL when the fetch fails.
   */
  public function getPostalCodes(): ?array {
    return $this->fetch(self::POSTAL_CODES_CID, fn (): array => $this->serviceMap->getPostalCodes());
  }

  /**
   * Gets the resident parking zone names of Helsinki.
   *
   * @return list<string>|null
   *   Unique zone names in order, or NULL when the fetch fails.
   */
  public function getParkingZoneNames(): ?array {
    return $this->fetch(self::PARKING_ZONES_CID, fn (): array => $this->parkingZoneService->getParkingZoneNames());
  }

  /**
   * Gets cached values or fetches them.
   *
   * @param string $cid
   *   The cache id.
   * @param callable(): array<string> $fetch
   *   Fetches the values.
   *
   * @return list<string>|null
   *   The values, or NULL when the fetch fails.
   */
  private function fetch(string $cid, callable $fetch): ?array {
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    try {
      $values = array_values(array_unique($fetch()));
    }
    catch (GuzzleException $e) {
      Error::logException($this->logger, $e);
      return NULL;
    }

    // The service gives an empty list for a response it cannot parse.
    if (!$values) {
      return NULL;
    }

    sort($values);
    $this->cache->set($cid, $values, $this->time->getRequestTime() + self::CACHE_LIFETIME);

    return $values;
  }

}
