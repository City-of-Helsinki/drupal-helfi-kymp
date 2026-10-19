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
   * Max distance from the line to an address (in metres).
   */
  private const int MAX_DISTANCE_METRES = 40;

  /**
   * Metres per degree of latitude.
   */
  private const float METRES_PER_DEGREE = 111320;

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
   * Resolves the address ranges from the geometry.
   *
   * @param string $address
   *   The MobileNote address.
   * @param object $geometry
   *   The GeoJSON in WGS84.
   * @param list<Address> $results
   *   Address results from the Paikkatieto API.
   *
   * @return string
   *   The address ranges, e.g. "Tähtitorninkatu 8-12, 15".
   */
  public static function resolveFromGeometry(string $address, object $geometry, array $results): string {
    $parsed = self::parse($address);
    if (!$parsed) {
      return $address;
    }

    // Each part is separate linestring.
    $parts = self::parts($geometry);
    if (!$parts) {
      return $parsed->address;
    }

    $origin = $parts[0][0];
    $original = $parsed->numbers[0];
    $partSegments = array_map(static fn (array $part) => self::segments($part, $origin), $parts);
    $addresses = self::addresses($parsed->street, $results, $origin);

    // The part and side of the line that the original address is on.
    $match = array_find($addresses, static fn (array $address) => $address['number'] === $original);
    if ($match) {
      $anchor = self::nearestPart($match['point'], $partSegments, FALSE);
    }
    elseif (count($parts) === 1) {
      $anchor = ['part' => 0, 'side' => NULL];
    }
    else {
      $anchor = NULL;
    }

    // Assign the addresses alongside the line to the nearest part.
    $candidates = [];
    foreach ($addresses as $address) {
      $nearest = self::nearestPart($address['point'], $partSegments, TRUE);
      if ($nearest && $nearest['distance'] <= self::MAX_DISTANCE_METRES) {
        $candidates[] = $nearest + $address;
      }
    }
    usort($candidates, static fn (array $a, array $b) => $a['distance'] <=> $b['distance']);

    // The side of each part: the anchor side, or the nearest candidate's side.
    $sides = [];
    foreach ($candidates as $candidate) {
      $sides[$candidate['part']] ??= $candidate['side'];
    }
    if (isset($anchor['side'])) {
      $sides[$anchor['part']] = $anchor['side'];
    }

    // Group the numbers on the near side of each part by parity.
    $numbers = [];
    foreach ($candidates as $candidate) {
      if ($candidate['side'] === $sides[$candidate['part']]) {
        $numbers[$candidate['part']][$candidate['number'] % 2][] = $candidate['number'];
      }
    }
    ksort($numbers);

    // Add the original numbers to the part the address is on. If the part is
    // unknown, use the part with the closest numbers of the same parity, or
    // keep the numbers in a group of their own. The original numbers stay in
    // one range even if their parity differs.
    $part = $anchor['part'] ?? self::closestPart($numbers, $original) ?? count($parts);
    foreach ($parsed->numbers as $number) {
      $numbers[$part][$original % 2][] = $number;
    }

    $ranges = [];
    foreach ($numbers as $groups) {
      foreach ($groups as $group) {
        $ranges[] = [min($group), max($group)];
      }
    }
    $ranges = self::mergeRanges($ranges);

    if ($ranges === [[$original, $original]]) {
      return $parsed->address;
    }

    return $parsed->street . ' ' . implode(', ', array_map(
      static fn (array $range) => $range[0] === $range[1] ? (string) $range[0] : "$range[0]-$range[1]",
      $ranges,
    ));
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
   * Splits the geometry into separate parts, one per linestring.
   *
   * @param object $geometry
   *   The GeoJSON (Multi)LineString in WGS84, with a lowercased type.
   *
   * @return list<list<array{0: float, 1: float}>>
   *   The parts, each a linestring.
   */
  private static function parts(object $geometry): array {
    $lines = match ($geometry->type ?? '') {
      'linestring' => [$geometry->coordinates ?? []],
      'multilinestring' => $geometry->coordinates ?? [],
      default => [],
    };

    return array_values(array_filter($lines));
  }

  /**
   * Gets the numbered addresses on the street.
   *
   * @param string $street
   *   The street name.
   * @param list<Address> $results
   *   Address results from the Paikkatieto API.
   * @param array{0: float, 1: float} $origin
   *   The [lon, lat] origin for the metric projection.
   *
   * @return list<array{number: int, point: array{0: float, 1: float}}>
   *   The unique addresses, with points in metres.
   */
  private static function addresses(string $street, array $results, array $origin): array {
    $street = mb_strtolower($street);
    $addresses = [];

    foreach ($results as $result) {
      $names = array_map('mb_strtolower', array_filter([
        $result->street->name->fi ?? NULL,
        $result->street->name->sv ?? NULL,
      ]));
      $number = $result->number ?? '';
      $location = $result->location->coordinates ?? NULL;

      if (!in_array($street, $names, TRUE) || !$number || !is_array($location)) {
        continue;
      }

      // The same address may be returned by the search of several parts.
      $key = $number . '|' . ($result->letter ?? '') . '|' . implode(',', $location);
      $addresses[$key] = [
        'number' => (int) $number,
        'point' => self::toMetres($location, $origin),
      ];
    }

    return array_values($addresses);
  }

  /**
   * Gets the part nearest to a point.
   *
   * @param array{0: float, 1: float} $point
   *   The point in metres.
   * @param list<list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}>> $partSegments
   *   The segments of each part in metres.
   * @param bool $alongside
   *   Only count segments that the point projects onto.
   *
   * @return array{part: int, distance: float, side: bool|null}|null
   *   The part index, distance in metres and side, NULL if there is none.
   */
  private static function nearestPart(array $point, array $partSegments, bool $alongside): ?array {
    $nearest = NULL;

    foreach ($partSegments as $part => $segments) {
      [$distance, $side] = self::lineDistance($point, $segments, $alongside);
      if ($distance < ($nearest['distance'] ?? INF)) {
        $nearest = ['part' => $part, 'distance' => $distance, 'side' => $side];
      }
    }

    return $nearest;
  }

  /**
   * Gets the part with the numbers closest to the given number.
   *
   * @param array<int, array<int, non-empty-list<int>>> $numbers
   *   The numbers of each part, grouped by parity.
   * @param int $number
   *   The number.
   *
   * @return int|null
   *   The part index, NULL if no part has numbers of the same parity.
   */
  private static function closestPart(array $numbers, int $number): ?int {
    $closest = NULL;

    foreach ($numbers as $part => $groups) {
      if (isset($groups[$number % 2])) {
        $gap = max(min($groups[$number % 2]) - $number, $number - max($groups[$number % 2]), 0);
        if ($gap < ($closest['gap'] ?? INF)) {
          $closest = ['part' => $part, 'gap' => $gap];
        }
      }
    }

    return $closest['part'] ?? NULL;
  }

  /**
   * Gets the distance from a point to the nearest segment.
   *
   * @param array{0: float, 1: float} $point
   *   The point in metres.
   * @param list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}> $segments
   *   The [start, end] segments in metres.
   * @param bool $alongside
   *   Only count segments that the point projects onto (not past their
   *   ends).
   *
   * @return array{0: float, 1: bool|null}
   *   The distance in metres.
   */
  private static function lineDistance(array $point, array $segments, bool $alongside): array {
    $nearest = [INF, NULL];

    foreach ($segments as [$a, $b]) {
      $dx = $b[0] - $a[0];
      $dy = $b[1] - $a[1];
      $lengthSquared = $dx * $dx + $dy * $dy;
      $t = $lengthSquared == 0 ? 0 : (($point[0] - $a[0]) * $dx + ($point[1] - $a[1]) * $dy) / $lengthSquared;

      if ($t < 0 || $t > 1) {
        if ($alongside) {
          continue;
        }
        $t = max(0, min(1, $t));
      }
      elseif ($alongside && $lengthSquared == 0) {
        continue;
      }

      $distance = hypot($point[0] - $a[0] - $t * $dx, $point[1] - $a[1] - $t * $dy);
      if ($distance < $nearest[0]) {
        $nearest = [$distance, $dx * ($point[1] - $a[1]) - $dy * ($point[0] - $a[0]) > 0];
      }
    }

    return $nearest;
  }

  /**
   * Gets the segments of a linestring.
   *
   * @param list<array{0: float, 1: float}> $line
   *   The linestring.
   * @param array{0: float, 1: float} $origin
   *   The [lon, lat] origin.
   *
   * @return list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}>
   *   The [start, end] segments in metres.
   */
  private static function segments(array $line, array $origin): array {
    $points = array_map(static fn (array $point) => self::toMetres($point, $origin), $line);
    $segments = [[$points[0], $points[0]]];
    for ($i = 0; $i < count($points) - 1; $i++) {
      $segments[] = [$points[$i], $points[$i + 1]];
    }
    return $segments;
  }

  /**
   * Sorts the ranges and merges ranges that overlap on the same side.
   *
   * @param list<array{0: int, 1: int}> $ranges
   *   The [min, max] ranges.
   *
   * @return list<array{0: int, 1: int}>
   *   The merged ranges.
   */
  private static function mergeRanges(array $ranges): array {
    usort($ranges, static fn (array $a, array $b) => $a <=> $b);

    $merged = [];
    foreach ($ranges as $range) {
      foreach ($merged as &$existing) {
        if ($existing[0] % 2 === $range[0] % 2 && $range[0] <= $existing[1] + 2) {
          $existing[1] = max($existing[1], $range[1]);
          continue 2;
        }
      }
      unset($existing);
      $merged[] = $range;
    }

    return $merged;
  }

  /**
   * Projects a [lon, lat] point to metres relative to the origin.
   *
   * @param array{0: float, 1: float} $point
   *   The [lon, lat] point.
   * @param array{0: float, 1: float} $origin
   *   The [lon, lat] origin.
   *
   * @return array{0: float, 1: float}
   *   The [x, y] offset in metres.
   */
  private static function toMetres(array $point, array $origin): array {
    return [
      ($point[0] - $origin[0]) * self::METRES_PER_DEGREE * cos(deg2rad($origin[1])),
      ($point[1] - $origin[1]) * self::METRES_PER_DEGREE,
    ];
  }

}
