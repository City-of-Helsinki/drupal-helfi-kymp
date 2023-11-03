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
}
