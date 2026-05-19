<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Unit;

use Drupal\helfi_kymp_content\KarttaUtility;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests KarttaUtility map URL construction.
 */
#[Group('helfi_kymp_content')]
class KarttaUtilityTest extends UnitTestCase {

  /**
   * Tests KarttaUtility::buildUrl against expected output URLs.
   *
   * @param array<string, mixed> $geometry
   *   The input GeoJSON-shaped geometry in EPSG:3879.
   * @param string $expectedUrl
   *   The expected URL, or empty string when the input is degenerate.
   */
  #[DataProvider('buildUrlProvider')]
  public function testBuildUrl(array $geometry, string $expectedUrl): void {
    $this->assertSame($expectedUrl, KarttaUtility::buildUrl($geometry));
  }

  /**
   * Cases for testBuildUrl.
   *
   * @return array<string, array{0: array<string, mixed>, 1: string}>
   *   Each case has [input geometry, expected URL].
   */
  public static function buildUrlProvider(): array {
    // phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
    return [
      'LineString' => [
        ['type' => 'LineString', 'coordinates' => [[25502829.56906132, 6674206.427107554], [25503032.73799631, 6674249.873428466]]],
        'https://kartta.hel.fi/?e=25502931&n=6674228&r=2&l=Karttasarja&geom=POLYGON%20%28%2825502827.48%206674216.21%2C%2025503030.65%206674259.65%2C%2025503034.83%206674240.09%2C%2025502831.66%206674196.65%2C%2025502827.48%206674216.21%29%29',
      ],
      'single component MultiLineString' => [
        ['type' => 'MultiLineString', 'coordinates' => [[[25502829.56906132, 6674206.427107554], [25503032.73799631, 6674249.873428466]]]],
        'https://kartta.hel.fi/?e=25502931&n=6674228&r=2&l=Karttasarja&geom=MULTIPOLYGON%20%28%28%2825502827.48%206674216.21%2C%2025503030.65%206674259.65%2C%2025503034.83%206674240.09%2C%2025502831.66%206674196.65%2C%2025502827.48%206674216.21%29%29%29',
      ],
      'two-component MultiLineString' => [
        ['type' => 'MultiLineString', 'coordinates' => [[[25502829.56906132, 6674206.427107554], [25503032.73799631, 6674249.873428466]], [[25502852.73, 6674701.8], [25502923.47, 6674647.45]]]],
        'https://kartta.hel.fi/?e=25502910&n=6674451&r=2&l=Karttasarja&geom=MULTIPOLYGON%20%28%28%2825502827.48%206674216.21%2C%2025503030.65%206674259.65%2C%2025503034.83%206674240.09%2C%2025502831.66%206674196.65%2C%2025502827.48%206674216.21%29%29%2C%20%28%2825502858.82%206674709.73%2C%2025502929.56%206674655.38%2C%2025502917.38%206674639.52%2C%2025502846.64%206674693.87%2C%2025502858.82%206674709.73%29%29%29',
      ],
      'single-point LineString' => [
        ['type' => 'LineString', 'coordinates' => [[25502829.56906132, 6674206.427107554]]],
        '',
      ],
      'zero-length LineString' => [
        ['type' => 'LineString', 'coordinates' => [[25502829.56906132, 6674206.427107554], [25502829.56906132, 6674206.427107554]]],
        '',
      ],
      'empty MultiLineString' => [
        ['type' => 'MultiLineString', 'coordinates' => []],
        '',
      ],
      'MultiLineString with only degenerate parts' => [
        ['type' => 'MultiLineString', 'coordinates' => [[[25502829.56906132, 6674206.427107554]], []]],
        '',
      ],
    ];
    // phpcs:enable Drupal.Arrays.Array.LongLineDeclaration
  }

  /**
   * Tests that unsupported or missing geometry types throw.
   *
   * @param array<string, mixed> $geometry
   *   The geometry to pass to buildUrl.
   */
  #[TestWith([['type' => 'Point', 'coordinates' => [25497400, 6672500]]], 'Point')]
  #[TestWith([['type' => 'Polygon', 'coordinates' => []]], 'Polygon')]
  #[TestWith([['type' => 'MultiPoint', 'coordinates' => []]], 'MultiPoint')]
  #[TestWith([['type' => 'MultiPolygon', 'coordinates' => []]], 'MultiPolygon')]
  #[TestWith([['type' => 'GeometryCollection', 'coordinates' => []]], 'GeometryCollection')]
  #[TestWith([['type' => 'NotARealType', 'coordinates' => []]], 'garbage type')]
  #[TestWith([['coordinates' => [[25497400, 6672500], [25497500, 6672600]]]], 'missing type')]
  public function testBuildUrlThrowsForUnsupportedType(array $geometry): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/only supports LineString and MultiLineString/');

    KarttaUtility::buildUrl($geometry);
  }

}
