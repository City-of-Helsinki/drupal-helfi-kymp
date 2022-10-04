<?php

namespace Drupal\helfi_kymp_district_project_search\Plugin\search_api\data_type;

use Drupal\image\Entity\ImageStyle;
use Drupal\search_api\Plugin\search_api\data_type\StringDataType;
use Drupal\media\Entity\Media;

/**
 * Get absolute file url from media entity.
 *
 * @SearchApiDataType(
 *   id = "image_absolute_url",
 *   label = @Translation("Image absolute URL"),
 *   description = @Translation("Image absolute URL"),
 *   default = "true"
 * )
 */
class ImageAbsoluteUrl extends StringDataType {

  /**
   * {@inheritDoc}
   */
  public function getValue($value) {
    $media = Media::load($value);

    if ($file = $media->get('field_media_image')->entity) {
      $imageStyle = ImageStyle::load('3_2_s');
      return $imageStyle->buildUrl($file->getFileUri());
    }
  }
}
