<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Unit;

use Drupal\helfi_kymp_content\GeometryConverter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests GeometryConverter coordinate conversion.
 */
#[Group('helfi_kymp_content')]
class GeometryConverterTest extends UnitTestCase {

  /**
   * The service under test.
   */
  private GeometryConverter $sut;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sut = new GeometryConverter();
  }

  /**
   * Asserts the full converted coordinate structure matches expected values.
   *
   * @param array<string, mixed> $input
   *   The input geometry in EPSG:3879.
   * @param string $expectedType
   *   The expected lowercased GeoJSON type.
   * @param array<int|string, mixed> $expectedCoordinates
   *   The expected WGS84 coordinate structure (same nesting as input).
   */
  #[DataProvider('convertProvider')]
  public function testConvertMatchesExpected(array $input, string $expectedType, array $expectedCoordinates): void {
    $result = $this->sut->convertHelsinkiToWgs84($input);

    $this->assertSame($expectedType, $result->type);
    $this->assertEqualsWithDelta($expectedCoordinates, $result->coordinates, 1e-9);
  }

  /**
   * Cases for testConvertMatchesExpected.
   *
   * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<int|string, mixed>}>
   *   Each case has [input geometry, expected type, expected coordinates].
   */
  public static function convertProvider(): array {
    // phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
    return [
      'Point' => [
        ['type' => 'Point', 'coordinates' => [25497397.0, 6672506.0]],
        'point',
        [24.953116976443717, 60.165439774508805],
      ],
      'LineString (two points)' => [
        ['type' => 'LineString', 'coordinates' => [[25497397.0, 6672506.0], [25497500.0, 6672600.0]]],
        'linestring',
        [
          [24.953116976443717, 60.165439774508805],
          [24.954970969982263, 60.16628410919936],
        ],
      ],
      'LineString (three points, fractional input)' => [
        ['type' => 'LineString', 'coordinates' => [[25502829.56906132, 6674206.427107554], [25503032.73799631, 6674249.873428466], [25503150.0, 6674300.0]]],
        'linestring',
        [
          [25.050987435339152, 60.180700333662074],
          [25.054649085056408, 60.18108882416832],
          [25.056762888555568, 60.1815378433371],
        ],
      ],
      'MultiLineString (two components)' => [
        [
          'type' => 'MultiLineString',
          'coordinates' => [
            [[25497397.0, 6672506.0], [25497500.0, 6672600.0]],
            [[25497700.0, 6672800.0], [25497800.0, 6672900.0]],
          ],
        ],
        'multilinestring',
        [
          [
            [24.953116976443717, 60.165439774508805],
            [24.954970969982263, 60.16628410919936],
          ],
          [
            [24.958571031675763, 60.168080372010095],
            [24.960371209891186, 60.16897846657731],
          ],
        ],
      ],
      'Polygon (single ring)' => [
        [
          'type' => 'Polygon',
          'coordinates' => [
            [
              [25497397.0, 6672506.0],
              [25497500.0, 6672506.0],
              [25497500.0, 6672600.0],
              [25497397.0, 6672600.0],
              [25497397.0, 6672506.0],
            ],
          ],
        ],
        'polygon',
        [
          [
            [24.953116976443717, 60.165439774508805],
            [24.954972124214652, 60.16544041772914],
            [24.954970969982263, 60.16628410919936],
            [24.95311577465701, 60.1662834659572],
            [24.953116976443717, 60.165439774508805],
          ],
        ],
      ],
      'MultiPolygon (single polygon)' => [
        [
          'type' => 'MultiPolygon',
          'coordinates' => [
            [
              [
                [25497397.0, 6672506.0],
                [25497500.0, 6672506.0],
                [25497500.0, 6672600.0],
                [25497397.0, 6672506.0],
              ],
            ],
          ],
        ],
        'multipolygon',
        [
          [
            [
              [24.953116976443717, 60.165439774508805],
              [24.954972124214652, 60.16544041772914],
              [24.954970969982263, 60.16628410919936],
              [24.953116976443717, 60.165439774508805],
            ],
          ],
        ],
      ],
    ];
    // phpcs:enable Drupal.Arrays.Array.LongLineDeclaration
  }

  /**
   * Tests that unsupported or missing geometry types throw.
   *
   * @param array<string, mixed> $geometry
   *   The geometry to pass to the converter.
   */
  #[TestWith([['type' => 'GeometryCollection', 'coordinates' => []]], 'GeometryCollection')]
  #[TestWith([['type' => 'NotARealType', 'coordinates' => []]], 'garbage type')]
  #[TestWith([['type' => '', 'coordinates' => []]], 'empty type string')]
  #[TestWith([['coordinates' => [[25497400, 6672500], [25497500, 6672600]]]], 'missing type key')]
  public function testThrowsForUnsupportedType(array $geometry): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/unsupported geometry type/');

    $this->sut->convertHelsinkiToWgs84($geometry);
  }

}
