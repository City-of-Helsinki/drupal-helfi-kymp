<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Unit\Hakuvahti;

use Drupal\helfi_kymp_content\Hakuvahti\SearchDescriptionBuilder;
use Drupal\helfi_kymp_content\Hakuvahti\StreetNameLookup;
use Drupal\helfi_kymp_content\VehicleRemovalFilterValues;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SearchDescriptionBuilder.
 */
#[CoversClass(SearchDescriptionBuilder::class)]
#[Group('helfi_kymp_content')]
final class SearchDescriptionBuilderTest extends UnitTestCase {

  private const array KNOWN_STREETS = ['Mannerheimintie', 'Kaivokatu', 'Mannerheimvägen'];
  private const array KNOWN_ZONES = ['Kallio/Sörnäinen', 'Kamppi', 'Vallila'];

  /**
   * Creates the builder with known streets and zones.
   *
   * @param list<string> $knownStreets
   *   Street names the lookup knows.
   * @param list<string>|null $knownZones
   *   Zone names, or NULL if they cannot be fetched.
   */
  private function createBuilder(array $knownStreets = self::KNOWN_STREETS, ?array $knownZones = self::KNOWN_ZONES): SearchDescriptionBuilder {
    $lookup = $this->createMock(StreetNameLookup::class);
    $lookup->method('filterKnown')
      ->willReturnCallback(static fn (array $names): array => array_values(array_intersect($names, $knownStreets)));

    $filterValues = $this->createMock(VehicleRemovalFilterValues::class);
    $filterValues->method('getParkingZoneNames')->willReturn($knownZones);

    return new SearchDescriptionBuilder($lookup, $filterValues);
  }

  /**
   * Wraps should clauses in the query shape of the vehicle removal search.
   *
   * @param array<mixed> $should
   *   The should clauses.
   *
   * @return array<string, mixed>
   *   The query.
   */
  private static function query(array $should): array {
    return [
      'size' => 10,
      'from' => 0,
      'query' => [
        'function_score' => [
          'query' => ['bool' => ['should' => $should, 'minimum_should_match' => 1]],
          'functions' => [['linear' => ['valid_from' => ['origin' => 'now', 'scale' => '7d', 'decay' => 0.5]]]],
          'boost_mode' => 'replace',
        ],
      ],
    ];
  }

  /**
   * Data provider for testBuild().
   *
   * @return array<string, array{array<mixed>, string}>
   *   The query and the expected description.
   */
  public static function buildProvider(): array {
    return [
      'all filter types' => [
        self::query([
          ['terms' => ['street_names' => ['Mannerheimintie', 'Kaivokatu']]],
          ['terms' => ['postal_codes' => ['00100']]],
          ['terms' => ['parking_zone_names' => ['Kamppi']]],
        ]),
        'Mannerheimintie, Kaivokatu, 00100, Kamppi',
      ],
      'fixed type order' => [
        self::query([
          ['terms' => ['parking_zone_names' => ['Vallila', 'Kamppi']]],
          ['terms' => ['postal_codes' => ['00200', '00100']]],
          ['terms' => ['street_names' => ['Kaivokatu', 'Mannerheimvägen']]],
        ]),
        'Kaivokatu, Mannerheimvägen, 00200, 00100, Vallila, Kamppi',
      ],
      'clauses of the same field are merged' => [
        self::query([
          ['terms' => ['street_names' => ['Kaivokatu']]],
          ['terms' => ['street_names' => ['Mannerheimintie', 'Kaivokatu']]],
        ]),
        'Kaivokatu, Mannerheimintie',
      ],
      'duplicates' => [
        self::query([['terms' => ['street_names' => ['Kaivokatu', 'Kaivokatu'], 'postal_codes' => ['00100', '00100']]]]),
        'Kaivokatu, 00100',
      ],
      'unknown values' => [
        self::query([
          ['terms' => ['street_names' => ['Soita heti 0401234567', 'kaivokatu']]],
          ['terms' => ['parking_zone_names' => ['Tilisi suljetaan', 'kamppi', 'Kallio/Sörnäinen']]],
        ]),
        'Kallio/Sörnäinen',
      ],
      'postal code format' => [
        self::query([
          ['terms' => ['postal_codes' => ['00100', '0010', '00100x', ' 00100', "00100\n", '01000', '00999']]],
        ]),
        '00100, 00999',
      ],
      'non-string and empty values' => [
        self::query([['terms' => ['postal_codes' => [100, NULL, '', ['00100'], TRUE, '00200']]]]),
        '00200',
      ],
      'terms is not a list' => [
        self::query([
          ['terms' => ['postal_codes' => '00100']],
          ['terms' => 'x'],
          'x',
          ['term' => ['postal_codes' => '00100']],
        ]),
        '',
      ],
      'unknown field' => [
        self::query([['terms' => ['searchDescription' => ['00100']]]]),
        '',
      ],
      'match all' => [
        ['query' => ['function_score' => ['query' => ['match_all' => []]]]],
        '',
      ],
      'no function score' => [
        ['query' => ['bool' => ['should' => [['terms' => ['postal_codes' => ['00100']]]]]]],
        '',
      ],
    ];
  }

  /**
   * Tests the description.
   *
   * @param array<mixed> $query
   *   The query.
   * @param string $expected
   *   The expected description.
   */
  #[DataProvider('buildProvider')]
  public function testBuild(array $query, string $expected): void {
    $this->assertSame($expected, $this->createBuilder()->build($query));
  }

  /**
   * Tests that a should value other than a list gives no description.
   */
  public function testShouldIsNotArray(): void {
    $query = self::query([]);
    $query['query']['function_score']['query']['bool']['should'] = 'x';
    $this->assertSame('', $this->createBuilder()->build($query));
  }

  /**
   * Tests that at most 100 values of a field are collected.
   */
  public function testValueLimit(): void {
    $codes = array_map(static fn (int $i): string => \sprintf('00%03d', $i), range(0, 150));
    $description = $this->createBuilder()->build(self::query([['terms' => ['postal_codes' => $codes]]]));

    $this->assertSame(implode(', ', \array_slice($codes, 0, 100)), $description);
  }

  /**
   * Tests that whole values are left out when the description is too long.
   */
  public function testLength(): void {
    // 77 names of 11 characters with separators are exactly 999 characters.
    $streets = array_map(static fn (int $i): string => \sprintf('Ääkkönen %02d', $i), range(1, 78));
    $exact = \array_slice($streets, 0, 77);

    $description = $this->createBuilder($exact)->build(self::query([['terms' => ['street_names' => $exact]]]));
    $this->assertSame(implode(', ', $exact), $description);
    $this->assertSame(999, mb_strlen($description));

    $description = $this->createBuilder($streets)->build(self::query([['terms' => ['street_names' => $streets]]]));
    $this->assertSame(implode(', ', \array_slice($streets, 0, 76)) . ', …', $description);
    $this->assertLessThanOrEqual(999, mb_strlen($description));
  }

  /**
   * Tests a single value that is too long.
   */
  public function testSingleValueTooLong(): void {
    $zone = str_repeat('a', 1000);
    $description = $this->createBuilder(knownZones: [$zone])
      ->build(self::query([['terms' => ['parking_zone_names' => [$zone]]]]));

    $this->assertSame('', $description);
  }

  /**
   * Tests that zones are not fetched for a query without zones.
   */
  public function testZonesNotFetched(): void {
    $filterValues = $this->createMock(VehicleRemovalFilterValues::class);
    $filterValues->expects($this->never())->method('getParkingZoneNames');
    $lookup = $this->createMock(StreetNameLookup::class);
    $lookup->expects($this->once())->method('filterKnown')->with([])->willReturn([]);

    $builder = new SearchDescriptionBuilder($lookup, $filterValues);
    $this->assertSame('00100', $builder->build(self::query([['terms' => ['postal_codes' => ['00100']]]])));
  }

  /**
   * Tests that zones that cannot be checked fail the build.
   */
  public function testZonesUnavailable(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->createBuilder(knownZones: NULL)->build(self::query([['terms' => ['parking_zone_names' => ['Kamppi']]]]));
  }

  /**
   * Tests that a failed street lookup fails the build.
   */
  public function testStreetLookupFails(): void {
    $lookup = $this->createMock(StreetNameLookup::class);
    $lookup->method('filterKnown')->willThrowException(new \InvalidArgumentException('Street names could not be checked.'));

    $builder = new SearchDescriptionBuilder($lookup, $this->createMock(VehicleRemovalFilterValues::class));

    $this->expectException(\InvalidArgumentException::class);
    $builder->build(self::query([['terms' => ['street_names' => ['Kaivokatu']]]]));
  }

}
