<?php

declare(strict_types = 1);

namespace Drupal\helfi_kymp_content;

use Drupal\Core\Language\LanguageInterface;
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
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
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

    // Make sure the translated district parent includes the current node as a
    // sub-district.
    // @todo Get translated entity reference field value using the entity query
    // above, if possible.
    $parentIds = [];
    $langcode = \Drupal::languageManager()
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();
    foreach ($query->execute() as $parentId) {
      if (!\Drupal::entityTypeManager()->getStorage('node')->load($parentId)->hasTranslation($langcode)) {
        continue;
      }
      $translatedParent = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->load($parentId)->getTranslation($langcode);
      foreach ($translatedParent->get('field_subdistricts')->referencedEntities() as $subdistrict) {
        if ($subdistrict->id() === $node->id()) {
          $parentIds[] = $parentId;
          break;
        }
      }
    }
    return $parentIds;
  }

}
