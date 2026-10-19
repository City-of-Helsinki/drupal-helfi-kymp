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
 * @phpstan-type Point array{0: float, 1: float}
 * @phpstan-type Segment array{0: Point, 1: Point}
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
    $words = preg_split('/\s+/u', $address, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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

    // A MultiLineString street is handled one line at a time.
    $lines = self::lines($geometry);
    if (!$lines) {
      return $parsed->address;
    }

    [$candidates, $anchor] = self::getCandidates($parsed, $results, $lines);

    // The side of each line: the anchor side, or the nearest candidate's side.
    $sides = [];
    foreach ($candidates as $candidate) {
      $sides[$candidate['key']] ??= $candidate['side'];
    }
    if (isset($anchor['side'])) {
      $sides[$anchor['key']] = $anchor['side'];
    }

    // Group the numbers on the near side of each line by parity.
    $numbers = [];
    foreach ($candidates as $candidate) {
      if ($candidate['side'] === $sides[$candidate['key']]) {
        $numbers[$candidate['key']][$candidate['number'] % 2][] = $candidate['number'];
      }
    }
    ksort($numbers);

    // Add the original numbers to the line the address is on. If the line is
    // unknown, use the line with the closest numbers. The original numbers
    // stay in one range even if their parity differs.
    $original = $parsed->numbers[0];
    $line = $anchor['key'] ?? self::lineWithClosestNumbers($numbers, $original) ?? count($lines);
    foreach ($parsed->numbers as $number) {
      $numbers[$line][$original % 2][] = $number;
    }

    $ranges = [];
    foreach ($numbers as $groups) {
      foreach ($groups as $group) {
        $ranges[] = [min($group), max($group)];
      }
    }
    $ranges = self::mergeRanges($ranges);

    if ($ranges === [[min($parsed->numbers), max($parsed->numbers)]]) {
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
   * Gets the lines of the geometry.
   *
   * @param object $geometry
   *   The GeoJSON (Multi)LineString in WGS84, with a lowercased type.
   *
   * @return list<list<Point>>
   *   The lines, each a list of [lon, lat] points.
   */
  private static function lines(object $geometry): array {
    $lines = match ($geometry->type ?? '') {
      'linestring' => [$geometry->coordinates ?? []],
      'multilinestring' => $geometry->coordinates ?? [],
      default => [],
    };

    return array_values(array_filter($lines));
  }

  /**
   * Gets the addresses alongside the street and the line of the original.
   *
   * @param ParsedAddress $parsed
   *   The parsed MobileNote address.
   * @param list<Address> $results
   *   Address results from the Paikkatieto API.
   * @param non-empty-list<list<Point>> $lines
   *   The lines of the street, each a list of [lon, lat] points.
   *
   * @return array{0: list<array{key: int, distance: float, side: bool|null, number: int, point: Point}>, 1: array{key: int, side: bool|null}|null}
   *   The candidates nearest to a line, ordered by distance, and the anchor.
   *   Anchor is the line and side that the original address is on.
   */
  private static function getCandidates(object $parsed, array $results, array $lines): array {
    $origin = $lines[0][0];
    $lineSegments = array_map(static fn (array $line) => self::segments($line, $origin), $lines);

    $street = mb_strtolower($parsed->street);
    $addresses = [];

    // Collect potential addresses from Paikkatieto data.
    foreach ($results as $result) {
      $names = array_map('mb_strtolower', array_filter([
        $result->street->name->fi ?? NULL,
        $result->street->name->sv ?? NULL,
      ]));
      $number = $result->number ?? '';
      $point = $result->location->coordinates ?? NULL;

      if (!in_array($street, $names, TRUE) || !$number || !is_array($point)) {
        continue;
      }

      // The same address may be returned by the search of several lines.
      $key = $number . '|' . implode(',', $point);
      $addresses[$key] = [
        'number' => (int) $number,
        'point' => self::toMetres($point, $origin),
      ];
    }

    // The line and side of the line that the original address is on.
    $match = array_find($addresses, static fn (array $address) => in_array($address['number'], $parsed->numbers, TRUE));
    if ($match) {
      $anchor = self::lineNearestPoint($match['point'], $lineSegments, FALSE);
    }
    elseif (count($lines) === 1) {
      $anchor = ['key' => 0, 'side' => NULL];
    }
    else {
      $anchor = NULL;
    }

    // Assign the addresses alongside the street to the nearest line.
    $candidates = [];
    foreach ($addresses as $address) {
      $nearest = self::lineNearestPoint($address['point'], $lineSegments, TRUE);
      if ($nearest && $nearest['distance'] <= self::MAX_DISTANCE_METRES) {
        $candidates[] = $nearest + $address;
      }
    }
    usort($candidates, static fn (array $a, array $b) => $a['distance'] <=> $b['distance']);

    return [$candidates, $anchor];
  }

  /**
   * Gets the line nearest to a point.
   *
   * @param Point $point
   *   The point in metres.
   * @param list<list<Segment>> $lineSegments
   *   The segments of each line in metres.
   * @param bool $alongside
   *   Only count segments that the point projects onto.
   *
   * @return array{key: int, distance: float, side: bool|null}|null
   *   The line index, distance in metres and side, NULL if there is none.
   */
  private static function lineNearestPoint(array $point, array $lineSegments, bool $alongside): ?array {
    $nearest = NULL;

    foreach ($lineSegments as $line => $segments) {
      [$distance, $side] = self::lineDistance($point, $segments, $alongside);
      if ($distance < ($nearest['distance'] ?? INF)) {
        $nearest = ['key' => $line, 'distance' => $distance, 'side' => $side];
      }
    }

    return $nearest;
  }

  /**
   * Gets the line with the numbers closest to the given number.
   *
   * @param array<int, array<int, non-empty-list<int>>> $numbers
   *   The numbers of each line, grouped by parity.
   * @param int $number
   *   The number.
   *
   * @return int|null
   *   The line index, NULL if no line has numbers of the same parity.
   */
  private static function lineWithClosestNumbers(array $numbers, int $number): ?int {
    $closest = NULL;

    foreach ($numbers as $line => $groups) {
      if (isset($groups[$number % 2])) {
        $gap = max(min($groups[$number % 2]) - $number, $number - max($groups[$number % 2]), 0);
        if ($gap < ($closest['gap'] ?? INF)) {
          $closest = ['key' => $line, 'gap' => $gap];
        }
      }
    }

    return $closest['key'] ?? NULL;
  }

  /**
   * Gets the distance from a point to the nearest segment.
   *
   * @param Point $point
   *   The point in metres.
   * @param list<Segment> $segments
   *   The [start, end] segments in metres.
   * @param bool $alongside
   *   Only count segments that the point projects onto (not past their
   *   ends).
   *
   * @return array{0: float, 1: bool|null}
   *   The distance in metres, and whether the point is on the left side of
   *   the nearest segment in its direction of travel.
   */
  private static function lineDistance(array $point, array $segments, bool $alongside): array {
    $nearest = [INF, NULL];

    foreach ($segments as [$a, $b]) {
      $closest = self::closestPoint($point, $a, $b, $alongside);
      if (!$closest) {
        continue;
      }

      // Euclidean distance.
      $distance = hypot($point[0] - $closest[0], $point[1] - $closest[1]);

      if ($distance < $nearest[0]) {
        // The point is on the left of the segment (from A to B) if
        // the 2D cross-product (B - A) x (P - A) is positive.
        $cross = ($b[0] - $a[0]) * ($point[1] - $a[1]) - ($b[1] - $a[1]) * ($point[0] - $a[0]);
        $nearest = [$distance, $cross > 0];
      }
    }

    return $nearest;
  }

  /**
   * Gets the point on a segment closest to a point.
   *
   * @param Point $point
   *   The point.
   * @param Point $a
   *   The start of the segment.
   * @param Point $b
   *   The end of the segment.
   * @param bool $alongside
   *   Only accept the segment if the point projects onto it.
   *
   * @return Point|null
   *   The closest point in metres, NULL if the segment is not accepted.
   */
  private static function closestPoint(array $point, array $a, array $b, bool $alongside): ?array {
    $dx = $b[0] - $a[0];
    $dy = $b[1] - $a[1];
    $lengthSquared = $dx * $dx + $dy * $dy;

    if ($lengthSquared == 0) {
      return $alongside ? NULL : $a;
    }

    // Scalar projection parameter: t = (P - A) . (B - A) / |B - A|^2.
    $t = (($point[0] - $a[0]) * $dx + ($point[1] - $a[1]) * $dy) / $lengthSquared;

    // Past the ends of the segment: either skip it or clamp.
    if ($t < 0 || $t > 1) {
      if ($alongside) {
        return NULL;
      }
      $t = max(0, min(1, $t));
    }

    return [$a[0] + $t * $dx, $a[1] + $t * $dy];
  }

  /**
   * Gets the segments of a line.
   *
   * @param list<Point> $line
   *   The [lon, lat] points of the line.
   * @param Point $origin
   *   The [lon, lat] origin.
   *
   * @return list<Segment>
   *   The [start, end] segments in metres.
   */
  private static function segments(array $line, array $origin): array {
    $points = array_map(static fn (array $point) => self::toMetres($point, $origin), $line);

    // A single point is a zero-length segment. Otherwise avoid it, as it would
    // tie on distance with the first segment while having no direction.
    if (count($points) === 1) {
      return [[$points[0], $points[0]]];
    }

    $segments = [];
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
   * @param Point $point
   *   The [lon, lat] point.
   * @param Point $origin
   *   The [lon, lat] origin.
   *
   * @return Point
   *   The [x, y] offset in metres.
   */
  private static function toMetres(array $point, array $origin): array {
    return [
      ($point[0] - $origin[0]) * self::METRES_PER_DEGREE * cos(deg2rad($origin[1])),
      ($point[1] - $origin[1]) * self::METRES_PER_DEGREE,
    ];
  }

}
