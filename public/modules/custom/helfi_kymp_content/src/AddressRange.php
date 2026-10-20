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
   * The address info written by the user. We've seen texts
   * that repeat or continue the address:
   *  - Info that repeats the street name replaces the address:
   *    "Myrttitie 25" + "Myrttitie 25-29" -> "Myrttitie 25-29".
   *  - Info that starts with a number and mentions the house number, or a
   *    range that covers it:
   *    "Kuormakatu 4" + "8-2" -> "Kuormakatu 8-2".
   *  - Info that starts with "-" and a number continues the house number:
   *    "Ruusulankatu 5" + "-7" -> "Ruusulankatu 5-7".
   *  - Info that starts with another number is listed after the address:
   *    "Pohjoiskaari 27" + "43" -> "Pohjoiskaari 27, 43".
   *  - Anything else is appended after the address.
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

    // Collapse whitespace and normalize dashes between numbers.
    $addressInfo = preg_replace('/\s+/u', ' ', trim($addressInfo));
    $addressInfo = preg_replace('/(?<=\d)\s*[-–]\s*(?=\d)/u', '-', $addressInfo);

    $parsed = self::parse($address);
    if (!$parsed) {
      return trim("$address $addressInfo");
    }

    // The info repeats the street name.
    if (preg_match('/^' . preg_quote($parsed->street, '/') . '(?!\p{L})/iu', $addressInfo)) {
      return $parsed->street . mb_substr($addressInfo, mb_strlen($parsed->street));
    }

    // The info that starts with a number replaces the house number if one of
    // its numbers or ranges covers the house number, e.g. "4 ja 6" or "8-2".
    if (preg_match('/^\d/u', $addressInfo)) {
      preg_match_all('/(\d+)(?:-(\d+))?/u', $addressInfo, $matches, PREG_SET_ORDER);
      $covers = array_any($matches, static function (array $match) use ($parsed): bool {
        $range = [(int) $match[1], (int) ($match[2] ?? $match[1])];
        return array_any($parsed->numbers, static fn (int $number) => $number >= min($range) && $number <= max($range));
      });

      return $covers ? "$parsed->street $addressInfo" : "$address, $addressInfo";
    }

    // The info continues the house number, e.g. "-7" or "- 28".
    if (preg_match('/^[-–] ?(?=\d)(.*)$/u', $addressInfo, $matches)) {
      return "$address-$matches[1]";
    }

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
    $address = explode(',', $address)
        |> array_first(...)
        |> trim(...);

    // Parse the street name and the house number or range, ignoring a letter
    // or a stairwell, e.g. "Tie 1 A", "Tie 1-3" or "Tie 1 A 2".
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
