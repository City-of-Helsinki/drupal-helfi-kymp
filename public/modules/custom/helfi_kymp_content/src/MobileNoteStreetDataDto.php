<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

/**
 * The street api data used to enrich the mobile note data.
 */
class MobileNoteStreetDataDto {

  /**
   * The street names.
   *
   * @var array<string>
   */
  private array $streetNames = [];

  /**
   * The postal codes.
   *
   * @var array<string>
   */
  private array $postalCodes = [];

  /**
   * Add unique street names to an array.
   *
   * @param array<string> $streetNames
   *   The street names.
   */
  public function addStreetNames(array $streetNames): void {
    foreach ($streetNames as $streetName) {
      if (!in_array($streetName, $this->streetNames)) {
        $this->streetNames[] = $streetName;
      }
    }
  }

  /**
   * Get list of street names.
   *
   * @return array<string>
   *   List of unique street names
   */
  public function getStreetNames(): array {
    return $this->streetNames;
  }

  /**
   * Add unique postal codes to an array.
   *
   * @param array<string> $postalCodes
   *   The postal codes.
   */
  public function addPostalCodes(array $postalCodes): void {
    foreach ($postalCodes as $code) {
      if (!in_array($code, $this->postalCodes)) {
        $this->postalCodes[] = $code;
      }
    }
  }

  /**
   * Get list of postal codes.
   *
   * @return array<string>
   *   List of unique postal codes.
   */
  public function getPostalCodes(): array {
    return $this->postalCodes;
  }

}
