<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

/**
 * Helper for building kartta.hel.fi map URLs from GeoJSON geometries.
 */
class KarttaUtility {

  /**
   * The buffer width (in metres) applied around LineString segments.
   */
  protected const int BUFFER_METRES = 10;

  /**
   * Builds a kartta.hel.fi map URL for a LineString or MultiLineString.
   *
   * Buffers each LineString into a polygon and embeds it in the URL as
   * WKT (POLYGON for LineString, MULTIPOLYGON for MultiLineString).
   *
   * @param array<string, mixed> $geometry
   *   The source geometry in EPSG:3879 (GeoJSON-shaped: type + coordinates).
   *
   * @return string
   *   The kartta.hel.fi URL, or an empty string if the buffer ring cannot
   *   be built (degenerate input).
   *
   * @throws \InvalidArgumentException
   *   When the geometry type is not understood.
   */
  public static function buildUrl(array $geometry): string {
    $type = strtolower($geometry['type'] ?? '');
    $coordinates = $geometry['coordinates'] ?? [];

    return match ($type) {
      'linestring' => self::buildUrlForLineString($coordinates),
      'multilinestring' => self::buildUrlForMultiLineString($coordinates),
      default => throw new \InvalidArgumentException(sprintf(
        'KarttaUtility::buildUrl only supports LineString and MultiLineString, got "%s".',
        $type,
      )),
    };
  }

  /**
   * Builds the map URL for a LineString geometry.
   *
   * @param array<array{float, float}> $coordinates
   *   The LineString points in EPSG:3879.
   */
  protected static function buildUrlForLineString(array $coordinates): string {
    if (count($coordinates) < 2) {
      return '';
    }
    $ring = self::buildBufferRing($coordinates, self::BUFFER_METRES);
    if (empty($ring)) {
      return '';
    }
    $wkt = 'POLYGON (' . self::ringToWkt($ring) . ')';
    [$centerE, $centerN] = self::averageCenter($coordinates);
    return self::buildMapUrl($centerE, $centerN, $wkt);
  }

  /**
   * Builds the map URL for a MultiLineString geometry.
   *
   * @param array<array<array{float, float}>> $multiCoordinates
   *   The MultiLineString coordinate array.
   */
  protected static function buildUrlForMultiLineString(array $multiCoordinates): string {
    $rings = [];
    $flattened = [];
    foreach ($multiCoordinates as $segment) {
      if (count($segment) < 2) {
        continue;
      }
      $ring = self::buildBufferRing($segment, self::BUFFER_METRES);
      if (empty($ring)) {
        continue;
      }
      $rings[] = self::ringToWkt($ring);
      array_push($flattened, ...$segment);
    }
    if (empty($rings)) {
      return '';
    }
    $wkt = 'MULTIPOLYGON (' . implode(', ', array_map(static fn($r) => "($r)", $rings)) . ')';
    [$centerE, $centerN] = self::averageCenter($flattened);
    return self::buildMapUrl($centerE, $centerN, $wkt);
  }

  /**
   * Builds a closed perpendicular-offset ring around a LineString.
   *
   * @param array<array{float, float}> $coordinates
   *   The LineString points in EPSG:3879.
   * @param int $buffer
   *   Buffer width in metres.
   *
   * @return array<array{float, float}>
   *   The closed ring of polygon vertices, or an empty array if the
   *   LineString has no non-degenerate segments.
   */
  protected static function buildBufferRing(array $coordinates, int $buffer): array {
    $left = [];
    $right = [];
    $count = count($coordinates);

    for ($i = 0; $i < $count - 1; $i++) {
      $dx = $coordinates[$i + 1][0] - $coordinates[$i][0];
      $dy = $coordinates[$i + 1][1] - $coordinates[$i][1];
      $len = sqrt($dx * $dx + $dy * $dy);
      if ($len == 0) {
        continue;
      }
      // Perpendicular unit vector.
      $nx = -$dy / $len * $buffer;
      $ny = $dx / $len * $buffer;

      $left[] = [round($coordinates[$i][0] + $nx, 2), round($coordinates[$i][1] + $ny, 2)];
      $left[] = [round($coordinates[$i + 1][0] + $nx, 2), round($coordinates[$i + 1][1] + $ny, 2)];
      $right[] = [round($coordinates[$i][0] - $nx, 2), round($coordinates[$i][1] - $ny, 2)];
      $right[] = [round($coordinates[$i + 1][0] - $nx, 2), round($coordinates[$i + 1][1] - $ny, 2)];
    }

    if (empty($left)) {
      return [];
    }

    // Close the polygon: left side forward, right side reversed.
    $ring = array_merge($left, array_reverse($right));
    $ring[] = $ring[0];
    return $ring;
  }

  /**
   * Formats a closed ring as a WKT linear ring (with outer parentheses).
   *
   * @param array<array{float, float}> $ring
   *   The closed ring of polygon vertices.
   */
  protected static function ringToWkt(array $ring): string {
    $points = array_map(fn($p) => $p[0] . ' ' . $p[1], $ring);
    return '(' . implode(', ', $points) . ')';
  }

  /**
   * Returns the rounded [E, N] centroid of a flat list of coordinate pairs.
   *
   * @param array<array{float, float}> $coordinates
   *   A flat list of [x, y] pairs.
   *
   * @return array{int, int}
   *   Rounded [easting, northing] centroid in the input coordinate system.
   */
  protected static function averageCenter(array $coordinates): array {
    $count = count($coordinates);
    $sumX = $sumY = 0;
    foreach ($coordinates as $coord) {
      $sumX += $coord[0];
      $sumY += $coord[1];
    }
    return [(int) round($sumX / $count), (int) round($sumY / $count)];
  }

  /**
   * Builds the kartta.hel.fi URL for a centre point and WKT geometry.
   */
  protected static function buildMapUrl(int $centerE, int $centerN, string $wkt): string {
    // Rawurlencode uses %20 for spaces (required by kartta.hel.fi).
    return sprintf(
      'https://kartta.hel.fi/?e=%d&n=%d&r=2&l=Karttasarja&geom=%s',
      $centerE,
      $centerN,
      rawurlencode($wkt)
    );
  }

}
