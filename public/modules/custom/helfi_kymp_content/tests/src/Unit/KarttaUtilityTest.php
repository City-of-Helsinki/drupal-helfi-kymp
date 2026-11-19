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
   *   The expected URL, or empty string when there is nothing to centre on.
   */
  #[DataProvider('buildUrlProvider')]
  public function testBuildUrl(array $geometry, string $expectedUrl): void {
    $this->assertSame($expectedUrl, KarttaUtility::buildUrl($geometry, 73567));
  }

  /**
   * Cases for testBuildUrl.
   *
   * @return array<string, array{0: array<string, mixed>, 1: string}>
   *   Each case has [input geometry, expected URL].
   */
  public static function buildUrlProvider(): array {
    $layers = 'l=Karttasarja%2CAjoneuvojen_siirtokehotukset&w=%2Anro%3D%3D73567';

    // phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
    return [
      'LineString' => [
        ['type' => 'LineString', 'coordinates' => [[25502829.56906132, 6674206.427107554], [25503032.73799631, 6674249.873428466]]],
        "https://kartta.hel.fi/?e=25502931&n=6674228&r=2&$layers",
      ],
      'single component MultiLineString' => [
        ['type' => 'MultiLineString', 'coordinates' => [[[25502829.56906132, 6674206.427107554], [25503032.73799631, 6674249.873428466]]]],
        "https://kartta.hel.fi/?e=25502931&n=6674228&r=2&$layers",
      ],
      'two-component MultiLineString' => [
        ['type' => 'MultiLineString', 'coordinates' => [[[25502829.56906132, 6674206.427107554], [25503032.73799631, 6674249.873428466]], [[25502852.73, 6674701.8], [25502923.47, 6674647.45]]]],
        "https://kartta.hel.fi/?e=25502910&n=6674451&r=2&$layers",
      ],
      'single-point LineString' => [
        ['type' => 'LineString', 'coordinates' => [[25502829.56906132, 6674206.427107554]]],
        "https://kartta.hel.fi/?e=25502830&n=6674206&r=2&$layers",
      ],
      'empty LineString' => [
        ['type' => 'LineString', 'coordinates' => []],
        '',
      ],
      'empty MultiLineString' => [
        ['type' => 'MultiLineString', 'coordinates' => []],
        '',
      ],
      'MultiLineString with only empty parts' => [
        ['type' => 'MultiLineString', 'coordinates' => [[], []]],
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

    KarttaUtility::buildUrl($geometry, 73567);
  }

}
