<?php

declare(strict_types = 1);

namespace Drupal\helfi_kymp_district_project_search\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Block for rendering District and project search react app.
 *
 * @Block(
 *   id = "district_project_search_block",
 *   admin_label = @Translation("District and project search"),
 *   category = @Translation("HELfi District and project search")
 * )
 */
class DistrictProjectSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [
      '#markup' => '<div id="helfi-kymp-district-project-search"></div>',
      '#attached' => [
        'library' => [
          'helfi_kymp_district_project_search/district-project-search'
        ]
      ]
    ];
    return $build;
  }

}
