<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\TypedData;

use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\helfi_kymp_content\Plugin\DataType\StreetData;

/**
 * The street data definition.
 */
class StreetDataDefinition extends ComplexDataDefinitionBase {

  /**
   * {@inheritDoc}
   */
  public function getPropertyDefinitions(): array {
    if (!isset($this->propertyDefinitions)) {
      $this->propertyDefinitions = StreetData::propertyDefinitions();
    }
    return $this->propertyDefinitions;
  }

}
