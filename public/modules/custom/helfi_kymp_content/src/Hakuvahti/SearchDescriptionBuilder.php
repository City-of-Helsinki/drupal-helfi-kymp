<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Hakuvahti;

use Drupal\helfi_kymp_content\VehicleRemovalFilterValues;

/**
 * Builds the hakuvahti search description of the vehicle removal search.
 */
class SearchDescriptionBuilder {

  private const int MAX_LENGTH = 999;
  private const int MAX_VALUES = 100;
  private const string POSTAL_CODE_PATTERN = '/^00\d{3}$/D';
  private const string SEPARATOR = ', ';
  private const string ELLIPSIS = '…';
  private const array FIELDS = ['street_names', 'postal_codes', 'parking_zone_names'];

  public function __construct(
    private readonly StreetNameLookup $streetNameLookup,
    private readonly VehicleRemovalFilterValues $filterValues,
  ) {
  }

  /**
   * Builds the description from the known filter values of the query.
   *
   * @param array<array-key, mixed> $query
   *   The decoded elastic query.
   */
  public function build(array $query): string {
    $terms = $this->extractTerms($query);

    $postalCodes = array_filter(
      $terms['postal_codes'],
      static fn (string $code): bool => preg_match(self::POSTAL_CODE_PATTERN, $code) === 1,
    );

    $zones = [];
    if ($terms['parking_zone_names']) {
      $knownZones = $this->filterValues->getParkingZoneNames()
        ?? throw new \InvalidArgumentException('Parking zones could not be checked.');
      $zones = array_intersect($terms['parking_zone_names'], $knownZones);
    }

    return $this->join([
      ...$this->streetNameLookup->filterKnown($terms['street_names']),
      ...$postalCodes,
      ...$zones,
    ]);
  }

  /**
   * Collects the unique string values of the filter fields.
   *
   * @param array<array-key, mixed> $query
   *   The decoded elastic query.
   *
   * @return array{street_names: list<string>, postal_codes: list<string>, parking_zone_names: list<string>}
   *   The values of each field, in query order.
   */
  private function extractTerms(array $query): array {
    $terms = array_fill_keys(self::FIELDS, []);
    $should = self::getPath($query, ['query', 'function_score', 'query', 'bool', 'should']);

    foreach (\is_array($should) ? $should : [] as $clause) {
      $clauseTerms = self::getPath($clause, ['terms']);

      foreach (\is_array($clauseTerms) ? $clauseTerms : [] as $field => $values) {
        if (!isset($terms[$field]) || !\is_array($values)) {
          continue;
        }

        foreach ($values as $value) {
          if (!\is_string($value) || $value === '' || \in_array($value, $terms[$field], TRUE)) {
            continue;
          }
          if (\count($terms[$field]) >= self::MAX_VALUES) {
            throw new \InvalidArgumentException(\sprintf('Too many %s values.', $field));
          }
          $terms[$field][] = $value;
        }
      }
    }

    return $terms;
  }

  /**
   * Gets a nested array value.
   *
   * @param mixed $data
   *   The data.
   * @param list<string> $keys
   *   The keys.
   */
  private static function getPath(mixed $data, array $keys): mixed {
    foreach ($keys as $key) {
      if (!\is_array($data) || !\array_key_exists($key, $data)) {
        return NULL;
      }
      $data = $data[$key];
    }
    return $data;
  }

  /**
   * Joins the values, leaving out whole values that do not fit.
   *
   * @param array<string> $values
   *   The values.
   */
  private function join(array $values): string {
    $description = implode(self::SEPARATOR, $values);
    if (mb_strlen($description) <= self::MAX_LENGTH) {
      return $description;
    }

    $maxLength = self::MAX_LENGTH - mb_strlen(self::SEPARATOR . self::ELLIPSIS);
    $description = '';
    foreach ($values as $value) {
      $next = $description === '' ? $value : $description . self::SEPARATOR . $value;
      if (mb_strlen($next) > $maxLength) {
        break;
      }
      $description = $next;
    }

    return $description === '' ? '' : $description . self::SEPARATOR . self::ELLIPSIS;
  }

}
