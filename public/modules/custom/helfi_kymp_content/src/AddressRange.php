<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

/**
 * Resolves street address ranges for MobileNote addresses.
 *
 * @phpstan-import-type Address from \Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient
 *
 * @phpstan-type ParsedAddress object{
 *   street: string,
 *   numbers: non-empty-list<int>,
 *   address: string
 * }
 */
final readonly class AddressRange {

  /**
   * Resolves the address with the address info.
   *
   * The address info can repeat the end of the address:
   *  * "Myrttitie 25" and "Myrttitie 25-29"
   *  * "Laajalahdentie 16", "16-10 & 11"
   *  * "Laajalahdentie 16", "10-16 & 11".
   *
   * @param string $address
   *   The MobileNote address, possibly with a city suffix.
   * @param string $addressInfo
   *   The MobileNote address info.
   *
   * @return string
   *   The merged address.
   */
  public static function resolveFromAddressInfo(string $address, string $addressInfo): string {
    // Remove the postal code and city suffix, e.g. ", 00100, Helsinki".
    $address = explode(',', $address)
        |> array_first(...)
        |> trim(...);

    // Remove the longest whole-word suffix of the address that matches
    // address info.
    $words = preg_split('/\s+/u', $address, -1, PREG_SPLIT_NO_EMPTY);
    foreach (array_keys($words) as $i) {
      $suffix = preg_quote(implode(' ', array_slice($words, $i)), '/');
      $rest = preg_replace('/^' . $suffix . '(?![\p{L}\d])/iu', '', $addressInfo, 1, $count);
      if ($count) {
        return $address . $rest;
      }
    }

    // Remove the numbers from the address that the address info also contains.
    preg_match_all('/\d+/u', $addressInfo, $matches);
    $numbers = array_map('intval', $matches[0]);
    $address = preg_replace_callback(
      '/\d+/u',
      static fn (array $match) => in_array((int) $match[0], $numbers, TRUE) ? '' : $match[0],
      $address,
    );

    // Clean up separators left behind, e.g. "Tie 1-" -> "Tie 1".
    $address = trim(preg_replace('/\s+/u', ' ', $address), " \t-–,&");

    return trim("$address $addressInfo");
  }

  /**
   * Resolves the address range from the geometry.
   *
   * Takes the nearest address on the same side of the street to each point of
   * the line and returns the range from the smallest to the largest number.
   *
   * @param string $address
   *   The MobileNote address.
   * @param object $geometry
   *   The GeoJSON (Multi)LineString in WGS84, with a lowercased type.
   * @param list<Address> $results
   *   Address results from the Paikkatieto API.
   *
   * @return string
   *   The address range, e.g. "Tähtitorninkatu 8-12".
   */
  public static function resolveFromGeometry(string $address, object $geometry, array $results): string {
    $parsed = self::parse($address);
    if (!$parsed) {
      return $address;
    }

    $points = match ($geometry->type ?? '') {
      'linestring' => $geometry->coordinates ?? [],
      'multilinestring' => array_merge(...($geometry->coordinates ?? [])),
      default => [],
    };

    // Odd and even numbers are on opposite sides of the street.
    $parity = $parsed->numbers[0] % 2;
    $numbers = $parsed->numbers;

    foreach ($points as $point) {
      $nearest = NULL;
      $nearestDistance = INF;

      foreach ($results as $result) {
        $number = (int) ($result->number ?? 0);
        $location = $result->location->coordinates ?? NULL;
        if (!self::isOnStreet($parsed->street, $result) || !$number || $number % 2 !== $parity || !is_array($location)) {
          continue;
        }

        $distance = self::distance($point, $location);
        if ($distance < $nearestDistance) {
          $nearest = $number;
          $nearestDistance = $distance;
        }
      }

      if ($nearest !== NULL) {
        $numbers[] = $nearest;
      }
    }

    [$min, $max] = [min($numbers), max($numbers)];

    return $min === $max ? $parsed->address : "$parsed->street $min-$max";
  }

  /**
   * Parses a MobileNote address.
   *
   * @param string $address
   *   The address.
   *
   * @return ParsedAddress|null
   *   Parsed address, NULL if the parsing fails.
   */
  private static function parse(string $address): ?object {
    $address = trim(array_first(explode(',', $address)));

    if (!preg_match('/^(\p{L}[\p{L}\'\s.-]*?)\s+(\d+)\s*\p{L}?(?:\s*-\s*(\d+))?\b/u', $address, $matches)) {
      return NULL;
    }

    $numbers = [(int) $matches[2]];
    if (!empty($matches[3])) {
      $numbers[] = (int) $matches[3];
    }

    return (object) [
      'street' => $matches[1],
      'numbers' => $numbers,
      'address' => $address,
    ];
  }

  /**
   * Checks if the address result is on the street.
   *
   * @param string $street
   *   The street name.
   * @param Address $result
   *   The address result.
   *
   * @return bool
   *   TRUE if the Finnish or Swedish street name matches.
   */
  private static function isOnStreet(string $street, object $result): bool {
    $names = array_map('mb_strtolower', array_filter([
      $result->street->name->fi ?? NULL,
      $result->street->name->sv ?? NULL,
    ]));

    return in_array(mb_strtolower($street), $names, TRUE);
  }

  /**
   * Gets the approximate distance between two [lon, lat] points.
   *
   * @param array{0: float, 1: float} $a
   *   The first point.
   * @param array{0: float, 1: float} $b
   *   The second point.
   *
   * @return float
   *   The distance in degrees of latitude, only usable for comparison.
   */
  private static function distance(array $a, array $b): float {
    return hypot(($a[0] - $b[0]) * cos(deg2rad($a[1])), $a[1] - $b[1]);
  }

}
