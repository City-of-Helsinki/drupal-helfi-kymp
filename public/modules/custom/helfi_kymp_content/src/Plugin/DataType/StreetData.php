<?php

namespace Drupal\helfi_kymp_content\Plugin\DataType;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\Plugin\DataType\Map;

/**
 * @DataType(
 *   id = "street_data",
 *   label = @Translation("Street data"),
 *   constraints = {},
 *   definition_class = "\Drupal\helfi_kymp_content\TypedData\StreetDataDefinition"
 * )
 */
class StreetData extends Map {
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = [];

    $properties['id'] = DataDefinition::create('integer')
      ->setLabel('id')
      ->setRequired(TRUE);

    $properties['street_name'] = DataDefinition::create('string')
      ->setLabel('Street name')
      ->addConstraint('Range', ['min' => 0, 'max' => 255])
      ->setRequired(TRUE);

    $properties['maintenance_class'] = DataDefinition::create('integer')
      ->setLabel('Maintenance class')
      ->addConstraint('Range', ['min' => 0, 'max' => 5])
      ->setRequired(TRUE);

    return $properties;
  }

}