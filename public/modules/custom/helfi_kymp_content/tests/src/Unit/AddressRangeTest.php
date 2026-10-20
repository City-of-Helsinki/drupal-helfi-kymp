<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Unit;

use Drupal\helfi_kymp_content\AddressRange;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests AddressRange.
 *
 * The MobileNote cases in tests/fixtures/address_range.json are every
 * vehicle removal with address info that the production WFS API returned on
 * 2026-10-20.
 *
 * The user-edited address info is used to determine "correct" results for
 * geometry tests. Cases that the geometry cannot resolve correctly are skipped.
 *
 * @phpstan-import-type Address from \Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient
 *
 * @phpstan-type Fixture object{
 *   address: string,
 *   address_info: string,
 *   expected: object{address_info: string, geometry: string},
 *   geometry: object,
 *   results: list<Address>,
 *   skip?: bool
 * }
 */
#[CoversClass(AddressRange::class)]
#[Group('helfi_kymp_content')]
final class AddressRangeTest extends UnitTestCase {

  /**
   * Tests merging the address with the address info.
   */
  #[DataProvider('syntheticAddressInfoProvider')]
  #[DataProvider('addressInfoProvider')]
  public function testResolveFromAddressInfo(string $address, string $addressInfo, string $expected): void {
    $this->assertSame($expected, AddressRange::resolveFromAddressInfo($address, $addressInfo));
  }

  /**
   * Synthetic address info cases.
   *
   * @return \Generator<string, array{0: string, 1: string, 2: string}>
   *   Test cases.
   */
  public static function syntheticAddressInfoProvider(): \Generator {
    $cases = [
      ['Tähtitorninkatu 8, Helsinki', '8-10', 'Tähtitorninkatu 8-10'],
      ['Myrttitie 25, Helsinki', 'Myrttitie 25-29', 'Myrttitie 25-29'],
      ['Laajalahdentie 16, Helsinki', '16-10 & 11', 'Laajalahdentie 16-10 & 11'],
      ['Laajalahdentie 16, Helsinki', '10-16 & 11', 'Laajalahdentie 10-16 & 11'],
      ['Tie 1, Helsinki', '3, 1', 'Tie 3, 1'],
      ['Tie 1, Helsinki', '15-17', 'Tie 1, 15-17'],
      ['Tie 1, Helsinki', '11-13', 'Tie 1, 11-13'],
      ['Tie 1-3, Helsinki', '3, 5', 'Tie 3, 5'],
      ['Tie 1-3, Helsinki', '1-3 & 5', 'Tie 1-3 & 5'],
      ['Tie 1, Helsinki', 'kohdalla', 'Tie 1 kohdalla'],
      ['Tie 1, 00100, Helsinki', 'kohdalla', 'Tie 1 kohdalla'],
    ];
    foreach ($cases as [$address, $addressInfo, $expected]) {
      yield "$address | $addressInfo" => [$address, $addressInfo, $expected];
    }
  }

  /**
   * MobileNote address info cases.
   *
   * @return \Generator<string, array{0: string, 1: string, 2: string}>
   *   Test cases.
   */
  public static function addressInfoProvider(): \Generator {
    foreach (self::fixture() as $id => $case) {
      yield "$id $case->address | $case->address_info" => [
        $case->address,
        $case->address_info,
        $case->expected->address_info,
      ];
    }
  }

  /**
   * Tests resolving the address range from the geometry.
   *
   * @param string $address
   *   The MobileNote address.
   * @param object $geometry
   *   The GeoJSON geometry.
   * @param list<Address> $results
   *   The Paikkatieto results.
   * @param string $expected
   *   The expected address range.
   * @param bool $skip
   *   TRUE to skip a known failure.
   */
  #[DataProvider('geometryProvider')]
  public function testResolveFromGeometry(string $address, object $geometry, array $results, string $expected, bool $skip): void {
    $actual = AddressRange::resolveFromGeometry($address, $geometry, $results);

    if ($skip) {
      $this->assertNotSame($expected, $actual, 'The case passes now, remove "skip" from the fixture.');
      $this->markTestSkipped("Known failure, got \"$actual\".");
    }

    $this->assertSame($expected, $actual);
  }

  /**
   * MobileNote geometry cases.
   *
   * @return \Generator<string, array{0: string, 1: object, 2: list<Address>, 3: string, 4: bool}>
   *   Test cases.
   */
  public static function geometryProvider(): \Generator {
    foreach (self::fixture() as $id => $case) {
      yield "$id $case->address | $case->address_info" => [
        $case->address,
        $case->geometry,
        $case->results,
        $case->expected->geometry,
        $case->skip ?? FALSE,
      ];
    }
  }

  /**
   * Loads the MobileNote fixture.
   *
   * @return array<string, Fixture>
   *   The fixture, keyed by feature ID.
   *
   * @throws \JsonException
   */
  private static function fixture(): array {
    return (array) json_decode(
      (string) file_get_contents(__DIR__ . '/../../fixtures/address_range.json'),
      flags: JSON_THROW_ON_ERROR,
    );
  }

}
