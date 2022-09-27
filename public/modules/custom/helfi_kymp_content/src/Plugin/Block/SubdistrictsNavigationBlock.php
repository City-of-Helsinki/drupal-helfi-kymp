<?php

declare(strict_types = 1);

namespace Drupal\helfi_kymp_content\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'SubdistrictsNavigationBlock' block.
 *
 * @Block(
 *   id = "subdistricts_navigation",
 *   admin_label = @Translation("Subdistricts navigation"),
 * )
 */
class SubdistrictsNavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Constructs a new SubdistrictsNavigationBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, EntityTypeManagerInterface $entity_type_manager, LanguageManagerInterface $language_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
      $container->get('language_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Get currently viewed district node.
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface || $node->getType() != 'district') {
      return [];
    }

    $navigation = [];
    $currentLanguageId = $this->languageManager->getCurrentLanguage()->getId();

    // Get node IDs for districts that have the currently viewed district as a
    // sub-district.
    $parentDistrictIds = \Drupal::entityQuery('node')
      ->accessCheck(TRUE)
      ->condition('type', 'district')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('langcode', $currentLanguageId)
      ->exists('field_subdistricts')
      ->condition('field_subdistricts.entity:node.nid', $node->id())
      ->execute();

    // Check if the current node itself is a parent for sub-districts.
    if (!$node->get('field_subdistricts')->isEmpty()) {
      $parentDistrictIds[] = $node->id();
    }

    // Create the navigation structure.
    foreach ($parentDistrictIds as $parentId) {
      $parent = $this->entityTypeManager->getStorage('node')->load($parentId);
      $navigation[$parentId]['label'] = $parent->getTranslation($currentLanguageId)->label();
      $navigation[$parentId]['url'] = $parent->getTranslation($currentLanguageId)->toUrl();
      if ($node->id() == $parentId) {
        $navigation[$parentId]['active'] = TRUE;
      }

      // Add parent's sub-districts.
      $navigation[$parentId]['districts'] = [];
      $subdistricts = $parent->get('field_subdistricts')->referencedEntities();
      foreach ($subdistricts as $subdistrict) {
        $navigation[$parentId]['districts'][$subdistrict->id()]['label'] = $subdistrict->getTranslation($currentLanguageId)->label();
        $navigation[$parentId]['districts'][$subdistrict->id()]['url'] = $subdistrict->getTranslation($currentLanguageId)->toUrl();
        if ($node->id() == $subdistrict->id()) {
          $navigation[$parentId]['districts'][$subdistrict->id()]['active'] = TRUE;
        }
      }
    }

    return [
      '#theme' => 'subdistricts_navigation',
      '#navigation' => $navigation,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'user.permissions',
      'url.path',
      'url.query_args',
      'languages:language_content',
    ]);
  }

}
