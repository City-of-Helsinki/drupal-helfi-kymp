<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

/**
 * Resolves street address ranges from a MobileNote geometry.
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
   * Resolves the address ranges.
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
  public static function resolve(string $address, object $geometry, array $results): string {
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
    $anchorPart = count($parts) === 1 ? 0 : NULL;
    $anchorSide = NULL;

    $match = array_find($addresses, static fn (array $address) => $address['number'] === $original);
    if ($match) {
      $nearest = array_map(static fn (array $segments) => self::lineDistance($match['point'], $segments, FALSE), $partSegments);
      $distances = array_column($nearest, 0);
      $anchorPart ??= (int) array_search(min($distances), $distances, TRUE);
      $anchorSide = $nearest[$anchorPart][1];
    }

    // Candidate addresses alongside each part, keyed by part index.
    $candidates = [];

    foreach ($addresses as $address) {
      $best = NULL;
      foreach ($partSegments as $i => $segments) {
        [$distance, $side] = self::lineDistance($address['point'], $segments, TRUE);
        if ($distance <= self::MAX_DISTANCE_METRES && ($best === NULL || $distance < $best['distance'])) {
          $best = ['part' => $i, 'distance' => $distance, 'side' => $side] + $address;
        }
      }

      if ($best) {
        $candidates[$best['part']][] = $best;
      }
    }

    $ranges = [];
    foreach (array_keys($parts) as $i) {
      $partCandidates = $candidates[$i] ?? [];
      $isAnchor = $i === $anchorPart;

      if ($isAnchor && $anchorSide !== NULL) {
        $side = $anchorSide;
      }
      elseif ($partCandidates) {
        usort($partCandidates, static fn (array $a, array $b) => $a['distance'] <=> $b['distance']);
        $side = $partCandidates[0]['side'];
      }
      else {
        $side = NULL;
      }

      // Group the numbers on the near side by parity.
      $numbers = [];
      foreach ($partCandidates as $candidate) {
        if ($candidate['side'] === $side) {
          $numbers[$candidate['number'] % 2][] = $candidate['number'];
        }
      }
      if ($isAnchor) {
        foreach ($parsed->numbers as $number) {
          $numbers[$number % 2][] = $number;
        }
      }

      foreach ($numbers as $group) {
        $ranges[] = [min($group), max($group)];
      }
    }

    // The original address does not belong to any part.
    if ($anchorPart === NULL) {
      $closest = NULL;
      foreach ($ranges as $i => [$min, $max]) {
        $gap = max($min - $original, $original - $max, 0);
        if ($min % 2 === $original % 2 && ($closest === NULL || $gap < $closest[1])) {
          $closest = [$i, $gap];
        }
      }

      if ($closest) {
        $ranges[$closest[0]] = [
          min($ranges[$closest[0]][0], ...$parsed->numbers),
          max($ranges[$closest[0]][1], ...$parsed->numbers),
        ];
      }
      else {
        $ranges[] = [min($parsed->numbers), max($parsed->numbers)];
      }
    }

    $ranges = self::mergeRanges($ranges);

    if (count($ranges) === 1 && $ranges[0] === [$original, $original]) {
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
   *   The [lon, lat] origin for the metric projection.
   *
   * @return list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}>
   *   The [start, end] segments in metres. A single-point linestring is a
   *   zero-length segment.
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
