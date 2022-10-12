<?php

namespace Drupal\helfi_kymp_content\Commands;

use Drush\Commands\DrushCommands;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;

/**
 * A drush command file.
 *
 * @package Drupal\helfi_kymp_content\Commands
 */
class KympContentDrushCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Constructs a new Drush commands instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityRepositoryInterface $entity_repository) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityRepository = $entity_repository;
  }

  /**
   * Drush command that creates District nodes.
   *
   * Drush command that creates District nodes with translations
   * from Project district taxonomy terms.
   *
   * @command helfi_kymp:create-district-nodes-from-taxonomy
   * @usage helfi_kymp:create-district-nodes-from-taxonomy
   */
  public function createDistrictNodesFromTaxonomyTerms() {
    $originalLang = 'fi';
    $translationLanguages = ['sv', 'en'];
    $vocabulary = 'project_district';

    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', $vocabulary);
    $query->condition('langcode', $originalLang);
    $tids = $query->execute();

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);

    foreach ($terms as $term) {
      $node = Node::create([
        'type' => 'district',
        'title' => $term->getName(),
        'uid' => 1,
      ]);
      $node->save();
      $this->output()->writeln('District node with name: ' . $term->getName() . ' created');

      foreach ($translationLanguages as $lang) {
        if ($term->hasTranslation($lang)) {
          $translated_term = $this->entityRepository->getTranslationFromContext($term, $lang);

          if (!$node->hasTranslation($lang)) {
            $node_translation = $node->addTranslation($lang);
            $node_translation->set('title', $translated_term->getName());
            $node_translation->set('uid', 1);
            $node_translation->save();
            $this->output()->writeln('District node translation (' . $lang . ') with name: ' . $translated_term->getName() . ' created');
          }
        }
      }
    }
  }

}
