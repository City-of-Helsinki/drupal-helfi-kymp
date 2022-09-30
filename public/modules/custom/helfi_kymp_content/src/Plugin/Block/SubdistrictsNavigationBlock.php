<?php

declare(strict_types = 1);

namespace Drupal\helfi_kymp_content\Plugin\Block;

use Drupal;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\node\Entity\Node;
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
    $parent_title = $this->t('Home');
    $parent_url = '/';
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
      // The districts have path alias so that the parent content
      // title is aliased to the path but they don't have any
      // other real connection to the previous content.
      // This is why we need to get the aliased path and convert
      // it to a /node/X/ form and also get the node title.
      $current_path = \Drupal::service('path.current')->getPath();
      $current_alias = \Drupal::service('path_alias.manager')->getAliasByPath($current_path);
      // Break down the current alias and rebuild it back without the
      // current node.
      $path_args = explode('/', $current_alias);
      // Make sure the path has the correct components.
      if (isset($path_args[2])) {
        $parent_alias = '/' . $path_args[1] . '/' . $path_args[2];
        $parent_path = \Drupal::service('path_alias.manager')->getPathByAlias($parent_alias);
        // Load the node based on the parent path and get the title.
        if (preg_match('/node\/(\d+)/', $parent_path, $matches)) {
          $parent_node = Node::load($matches[1]);
          $parent_title = $parent_node->getTitle();
        }
        $url = \Drupal::service('path.validator')->getUrlIfValid($parent_path);
        if ($parent_alias !== $parent_path) {
          $parent_url = $url->toString();
        }
      }

      $menu_item = 'menu_link_content:' . $parentId;
      $parent = $this->entityTypeManager->getStorage('node')->load($parentId);

      $navigation[$menu_item]['is_expanded'] = FALSE;
      if ($parent->get('field_subdistricts')->referencedEntities()) {
        $navigation[$menu_item]['is_expanded'] = TRUE;
      }

      $navigation[$menu_item]['is_collapsed'] = FALSE;

      $navigation[$menu_item]['in_active_trail'] = FALSE;
      if ($node->id() == $parentId) {
        $navigation[$menu_item]['in_active_trail'] = TRUE;
      }

      $navigation[$menu_item]['attributes'] = new Attribute([
        'class' => [
          'menu__item',
          'menu__item--children',
          'menu__item--item-below',
        ],
      ]);
      $navigation[$menu_item]['title'] = $parent->getTranslation($currentLanguageId)->label();
      $navigation[$menu_item]['url'] = $parent->getTranslation($currentLanguageId)->toUrl();

      // Check if url is current page where we are now.
      $current_uri = Drupal::request()->getRequestUri();
      if ($navigation[$menu_item]['url']->toString() == $current_uri) {
        $navigation[$menu_item]['is_currentPage'] = TRUE;
      }

      // Add parent's sub-districts.
      $navigation[$menu_item]['below'] = [];
      $subdistricts = $parent->get('field_subdistricts')->referencedEntities();
      foreach ($subdistricts as $subdistrict) {
        $navigation[$menu_item]['below'][$subdistrict->id()]['is_expanded'] = FALSE;
        $navigation[$menu_item]['below'][$subdistrict->id()]['is_collapsed'] = FALSE;
        if ($node->id() == $subdistrict->id()) {
          $navigation[$menu_item]['in_active_trail'] = TRUE;
          $navigation[$menu_item]['below'][$subdistrict->id()]['in_active_trail'] = TRUE;
        }
        $navigation[$menu_item]['below'][$subdistrict->id()]['attributes'] = new Attribute([
          'class' => 'menu__item',
        ]);
        $navigation[$menu_item]['below'][$subdistrict->id()]['title'] = $subdistrict->getTranslation($currentLanguageId)->label();
        $navigation[$menu_item]['below'][$subdistrict->id()]['url'] = $subdistrict->getTranslation($currentLanguageId)->toUrl();
        if ($navigation[$menu_item]['below'][$subdistrict->id()]['url']->toString() == $current_uri) {
          $navigation[$menu_item]['below'][$subdistrict->id()]['is_currentPage'] = TRUE;
        }
      }
    }

    return [
      '#theme' => 'subdistricts_navigation',
      '#navigation' => $navigation,
      'parent_title' => $parent_title,
      'parent_url' => $parent_url,
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

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['node_list:district']);
  }

}
