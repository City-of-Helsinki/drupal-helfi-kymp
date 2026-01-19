<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\DataType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\Plugin\DataType\Map;
use Drupal\helfi_kymp_content\TypedData\MobileNoteDataDefinition;

/**
 * MobileNote data type for parking sign data.
 */
#[DataType(
  id: "mobilenote_data",
  label: new TranslatableMarkup("MobileNote data"),
  definition_class: MobileNoteDataDefinition::class,
  constraints: [],
)]
class MobileNoteData extends Map {

  /**
   * Get property definitions.
   *
   * @return array
   *   Property definitions.
   */
  public static function propertyDefinitions(): array {
    $properties = [];

    $properties['id'] = DataDefinition::create('string')
      ->setLabel('ID')
      ->setDescription('Feature ID from WFS')
      ->setRequired(TRUE);

    $properties['address'] = DataDefinition::create('string')
      ->setLabel('Address')
      ->setDescription('Street address');

    $properties['reason'] = DataDefinition::create('string')
      ->setLabel('Reason')
      ->setDescription('Sign reason');

    $properties['valid_from'] = DataDefinition::create('integer')
      ->setLabel('Valid from')
      ->setDescription('Start timestamp');

    $properties['valid_to'] = DataDefinition::create('integer')
      ->setLabel('Valid to')
      ->setDescription('End timestamp');

    $properties['time_range'] = DataDefinition::create('string')
      ->setLabel('Time range')
      ->setDescription('Time description');

    $properties['created_at'] = DataDefinition::create('integer')
      ->setLabel('Created at')
      ->setDescription('Creation timestamp');

    $properties['updated_at'] = DataDefinition::create('integer')
      ->setLabel('Updated at')
      ->setDescription('Update timestamp');

    $properties['address_info'] = DataDefinition::create('string')
      ->setLabel('Address info')
      ->setDescription('Additional address information');

    $properties['sign_type'] = DataDefinition::create('string')
      ->setLabel('Sign type')
      ->setDescription('Type of sign');

    $properties['additional_text'] = DataDefinition::create('string')
      ->setLabel('Additional text')
      ->setDescription('Extra text on sign');

    $properties['notes'] = DataDefinition::create('string')
      ->setLabel('Notes')
      ->setDescription('Notes');

    $properties['phone'] = DataDefinition::create('string')
      ->setLabel('Phone')
      ->setDescription('Contact phone number');

    // Geometry as computed_geo_shape for Elasticsearch geo_shape queries.
    $properties['geometry'] = DataDefinition::create('any')
      ->setLabel('Geometry')
      ->setDescription('GeoJSON geometry for geo queries');

    return $properties;
  }

}
