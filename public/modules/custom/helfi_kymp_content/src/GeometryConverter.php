<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

use proj4php\Point;
use proj4php\Proj;
use proj4php\Proj4php;

/**
 * Service for converting GeoJSON geometries between coordinate systems.
 */
class GeometryConverter {

  /**
   * Proj4php environment configured for EPSG:3879 ↔ EPSG:4326.
   */
  private readonly Proj4php $proj4;

  /**
   * Source projection (EPSG:3879, Helsinki ETRS-GK25FIN).
   */
  private readonly Proj $projSource;

  /**
   * Target projection (EPSG:4326, WGS84).
   */
  private readonly Proj $projTarget;

  public function __construct() {
    // EPSG:3879 is Helsinki local CRS (ETRS-GK25FIN).
    $this->proj4 = new Proj4php();
    $this->proj4->addDef(
      'EPSG:3879',
      '+proj=tmerc +lat_0=0 +lon_0=25 +k=1 +x_0=25500000 +y_0=0 +ellps=GRS80 +towgs84=0,0,0,0,0,0,0 +units=m +no_defs'
    );
    $this->projSource = new Proj('EPSG:3879', $this->proj4);
    $this->projTarget = new Proj('EPSG:4326', $this->proj4);
  }

  /**
   * Converts a GeoJSON geometry from EPSG:3879 (Helsinki) to WGS84.
   *
   * @param array<string, mixed> $geometry
   *   The GeoJSON geometry as an associative array (type + coordinates).
   *
   * @return object{type: string, coordinates: array<int|string, mixed>}
   *   The converted geometry as an stdClass with a lowercased type and
   *   coordinates transformed to WGS84 [lon, lat] order.
   *
   * @throws \InvalidArgumentException
   *   When the geometry type is not a recognised GeoJSON geometry type.
   */
  public function convertHelsinkiToWgs84(array $geometry): object {
    $type = strtolower($geometry['type'] ?? '');

    $depth = match ($type) {
      'point' => 0,
      'multipoint', 'linestring' => 1,
      'polygon', 'multilinestring' => 2,
      'multipolygon' => 3,
      default => throw new \InvalidArgumentException(sprintf(
        'GeometryConverter: unsupported geometry type "%s".',
        $type,
      )),
    };

    return (object) [
      'type' => $type,
      'coordinates' => $this->transformCoordinates($geometry['coordinates'] ?? [], $depth),
    ];
  }

  /**
   * Recursively transforms a coordinate structure to WGS84.
   *
   * @param array<int|string, mixed> $coords
   *   Either a single [x, y] pair (when $depth is 0) or a nested array.
   * @param int $depth
   *   How many levels of array nesting wrap the [x, y] pairs.
   *
   * @return array<int|string, mixed>
   *   The transformed coordinate structure with the same nesting as input.
   */
  private function transformCoordinates(array $coords, int $depth): array {
    if ($depth === 0) {
      return $this->transformCoordinatePair($coords);
    }

    return array_map(
      fn(array $inner) => $this->transformCoordinates($inner, $depth - 1),
      $coords
    );
  }

  /**
   * Transforms a single [x, y] pair from EPSG:3879 to WGS84.
   *
   * @param array<int|string, mixed> $pair
   *   The [x, y] pair in EPSG:3879. Indexed by 0 and 1.
   *
   * @return array{0: float, 1: float}
   *   The [longitude, latitude] pair in WGS84 (GeoJSON order).
   */
  private function transformCoordinatePair(array $pair): array {
    $point = new Point($pair[0], $pair[1], $this->projSource);
    $transformed = $this->proj4->transform($this->projTarget, $point);
    return [$transformed->x, $transformed->y];
  }

}
