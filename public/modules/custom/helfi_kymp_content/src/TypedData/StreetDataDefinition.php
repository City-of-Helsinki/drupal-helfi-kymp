<?php

namespace Drupal\helfi_kymp_content\TypedData;

use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\Core\TypedData\DataDefinition;

/**
 * The street data definition.
 */
class StreetDataDefinition extends ComplexDataDefinitionBase {

  /**
   * {@inheritDoc}
   */
  public function getPropertyDefinitions() {
    if (!isset($this->propertyDefinitions)) {
      $this->propertyDefinitions['id'] = DataDefinition::create('integer')
        ->setLabel('id')
        ->setRequired(TRUE);
      $this->propertyDefinitions['street_name'] = DataDefinition::create('string')
        ->setLabel('Street name')
        ->addConstraint('Range', ['min' => 0, 'max' => 255])
        ->setRequired(TRUE);
      $this->propertyDefinitions['length'] = DataDefinition::create('integer')
        ->setLabel('Length')
        ->setRequired(TRUE);
      $this->propertyDefinitions['maintenance_class'] = DataDefinition::create('integer')
        ->setLabel('Maintenance class')
        ->addConstraint('Range', ['min' => 0, 'max' => 5])
        ->setRequired(TRUE);
    }
    return $this->propertyDefinitions;
  }

}
