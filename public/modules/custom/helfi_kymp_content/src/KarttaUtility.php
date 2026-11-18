<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

/**
 * Helper for building kartta.hel.fi map URLs for vehicle removal notices.
 */
class KarttaUtility {

  /**
   * The kartta.hel.fi base map layer.
   */
  public const string BASE_LAYER = 'Karttasarja';

  /**
   * The kartta.hel.fi layer with the vehicle removal notices.
   */
  public const string NOTICE_LAYER = 'Ajoneuvojen_siirtokehotukset';

  /**
   * Map resolution in metres per pixel.
   */
  protected const int RESOLUTION = 2;

  /**
   * Builds a kartta.hel.fi URL that shows a single notice on the map.
   *
   * @param array<string, mixed> $geometry
   *   The notice geometry in EPSG:3879 (GeoJSON-shaped: type + coordinates).
   * @param int $noticeNumber
   *   The notice number, called "nro" in MobileNote and on the map layer.
   *
   * @return string
   *   The kartta.hel.fi URL, or an empty string if the geometry has no
   *   coordinates to centre the map on.
   *
   * @throws \InvalidArgumentException
   *   When the geometry type is not understood.
   */
  public static function buildUrl(array $geometry, int $noticeNumber): string {
    $type = strtolower($geometry['type'] ?? '');
    $coordinates = $geometry['coordinates'] ?? [];

    $points = match ($type) {
      'linestring' => $coordinates,
      'multilinestring' => array_merge(...array_values(array_filter($coordinates, 'is_array'))),
      default => throw new \InvalidArgumentException(sprintf(
        'KarttaUtility::buildUrl only supports LineString and MultiLineString, got "%s".',
        $type,
      )),
    };

    if (empty($points)) {
      return '';
    }

    [$centerE, $centerN] = self::averageCenter($points);

    return sprintf(
      'https://kartta.hel.fi/?e=%d&n=%d&r=%d&l=%s&w=%s',
      $centerE,
      $centerN,
      self::RESOLUTION,
      rawurlencode(self::BASE_LAYER . ',' . self::NOTICE_LAYER),
      rawurlencode('*nro==' . $noticeNumber),
    );
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

}
