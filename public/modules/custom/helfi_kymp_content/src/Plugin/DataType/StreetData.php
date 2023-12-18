<?php

namespace Drupal\helfi_kymp_content\Plugin\DataType;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\Plugin\DataType\Map;

/**
 * Street data type.
 *
 * @DataType(
 *   id = "street_data",
 *   label = @Translation("Street data"),
 *   constraints = {},
 *   definition_class = "\Drupal\helfi_kymp_content\TypedData\StreetDataDefinition"
 * )
 */
class StreetData extends Map {

  /**
   * Get property definition.
   */
  public static function propertyDefinitions(): array {
    $properties = [];

    $properties['id'] = DataDefinition::create('integer')
      ->setLabel('id')
      ->setRequired(TRUE);

    $properties['street_name'] = DataDefinition::create('string')
      ->setLabel('Street name')
      ->addConstraint('Range', ['min' => 0, 'max' => 255])
      ->setRequired(TRUE);

    $properties['length'] = DataDefinition::create('integer')
      ->setLabel('Length')
      ->setRequired(TRUE);

    $properties['maintenance_class'] = DataDefinition::create('integer')
      ->setLabel('Maintenance class')
      ->addConstraint('Range', ['min' => 0, 'max' => 5])
      ->setRequired(TRUE);

    return $properties;
  }

}
