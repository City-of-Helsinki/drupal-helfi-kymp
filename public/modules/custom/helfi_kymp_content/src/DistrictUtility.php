<?php

declare(strict_types = 1);

namespace Drupal\helfi_kymp_content;

use Drupal\node\NodeInterface;

/**
 * Helper utility for district nodes.
 */
class DistrictUtility {

  /**
   * Get IDs for sub-district's parents.
   *
   * Get IDs for those parent nodes that has the $node referenced as a
   * sub-district at the field_subdistricts field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node that might have parent nodes.
   *
   * @return array
   *   The array containing the parent node IDs.
   */
  public static function getSubdistrictParentIds(NodeInterface $node): array {
    $query = \Drupal::entityQuery('node')
      ->accessCheck(TRUE)
      ->condition('type', 'district')
      ->condition('langcode', $node->language()->getId())
      ->exists('field_subdistricts')
      ->condition('field_subdistricts.entity:node.nid', $node->id());

    if (!\Drupal::currentUser()->isAuthenticated()) {
      $query->condition('status', NodeInterface::PUBLISHED);
    }

    return $query->execute();
  }

}
