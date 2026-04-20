<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\TypedData;

use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\helfi_kymp_content\Plugin\DataType\PaikkatietoStreetName;

/**
 * The paikkatieto street name data definition.
 */
class PaikkatietoStreetNameDefinition extends ComplexDataDefinitionBase {

  /**
   * {@inheritDoc}
   */
  public function getPropertyDefinitions(): array {
    if (empty($this->propertyDefinitions)) {
      $this->propertyDefinitions = PaikkatietoStreetName::propertyDefinitions();
    }
    return $this->propertyDefinitions;
  }

}
