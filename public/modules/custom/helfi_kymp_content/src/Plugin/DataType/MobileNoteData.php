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

    $fields = [
      'id' => ['type' => 'string', 'label' => 'ID', 'desc' => 'Feature ID from WFS', 'required' => TRUE],
      'address' => ['type' => 'string', 'label' => 'Address', 'desc' => 'Street address'],
      'reason' => ['type' => 'string', 'label' => 'Reason', 'desc' => 'Sign reason'],
      'valid_from' => ['type' => 'integer', 'label' => 'Valid from', 'desc' => 'Start timestamp'],
      'valid_to' => ['type' => 'integer', 'label' => 'Valid to', 'desc' => 'End timestamp'],
      'time_range' => ['type' => 'string', 'label' => 'Time range', 'desc' => 'Time description'],
      'created_at' => ['type' => 'integer', 'label' => 'Created at', 'desc' => 'Creation timestamp'],
      'updated_at' => ['type' => 'integer', 'label' => 'Updated at', 'desc' => 'Update timestamp'],
      'address_info' => ['type' => 'string', 'label' => 'Address info', 'desc' => 'Additional address information'],
      'sign_type' => ['type' => 'string', 'label' => 'Sign type', 'desc' => 'Type of sign'],
      'additional_text' => ['type' => 'string', 'label' => 'Additional text', 'desc' => 'Extra text on sign'],
      'notes' => ['type' => 'string', 'label' => 'Notes', 'desc' => 'Notes'],
      'phone' => ['type' => 'string', 'label' => 'Phone', 'desc' => 'Contact phone number'],
      'geometry' => ['type' => 'any', 'label' => 'Geometry', 'desc' => 'GeoJSON geometry for geo queries'],
    ];

    foreach ($fields as $key => $info) {
      $definition = DataDefinition::create($info['type'])
        ->setLabel($info['label'])
        ->setDescription($info['desc']);

      if (!empty($info['required'])) {
        $definition->setRequired(TRUE);
      }

      $properties[$key] = $definition;
    }

    return $properties;
  }

}
