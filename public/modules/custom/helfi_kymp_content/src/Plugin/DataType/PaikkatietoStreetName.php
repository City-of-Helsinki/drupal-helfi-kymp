<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\DataType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\Plugin\DataType\Map;
use Drupal\helfi_kymp_content\TypedData\PaikkatietoStreetNameDefinition;

/**
 * Paikkatieto street name data type.
 */
#[DataType(
  id: "paikkatieto_street_name",
  label: new TranslatableMarkup("Paikkatieto street name"),
  definition_class: PaikkatietoStreetNameDefinition::class,
  constraints: [],
)]
class PaikkatietoStreetName extends Map {

  /**
   * Get property definitions.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   The property definitions.
   */
  public static function propertyDefinitions(): array {
    $properties = [];

    $properties['id'] = DataDefinition::create('string')
      ->setLabel('ID')
      ->setRequired(TRUE);

    $properties['street_name'] = DataDefinition::create('string')
      ->setLabel('Street name')
      ->setRequired(TRUE);

    $properties['language'] = DataDefinition::create('string')
      ->setLabel('Language')
      ->setRequired(TRUE);

    return $properties;
  }

}
