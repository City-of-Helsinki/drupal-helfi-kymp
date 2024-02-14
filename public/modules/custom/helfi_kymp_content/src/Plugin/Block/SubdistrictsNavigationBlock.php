<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Template\Attribute;
use Drupal\helfi_kymp_content\DistrictUtility;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'SubdistrictsNavigationBlock' block.
 *
 * @Block(
 *   id = "subdistricts_navigation",
 *   admin_label = @Translation("Subdistricts navigation"),
 * )
 */
final class SubdistrictsNavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a new SubdistrictsNavigationBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The alias manager.
   * @param \Drupal\Core\Path\CurrentPathStack $currentPath
   *   The current path.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private RouteMatchInterface $routeMatch,
    private AliasManagerInterface $aliasManager,
    private CurrentPathStack $currentPath,
    private EntityTypeManagerInterface $entityTypeManager,
    private LanguageManagerInterface $languageManager,
    private RequestStack $requestStack,
    private AccountProxyInterface $currentUser
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('current_route_match'),
      $container->get('path_alias.manager'),
      $container->get('path.current'),
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('request_stack'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $currentLanguageId = $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    // Get currently viewed district node.
    $translatedNode = $this->routeMatch->getParameter('node')->getTranslation($currentLanguageId);
    if (!$translatedNode instanceof NodeInterface || $translatedNode->getType() != 'district') {
      return [];
    }

    $navigation = [];
    $parentTitle = $this->t('Home');
    $parentUrl = '/';

    // Get the sidebar navigation title from the current path structure.
    if ($titleParent = $this->getTitleParent()) {
      if ($titleParent->hasTranslation($currentLanguageId)) {
        $parentTitle = $titleParent->getTranslation($currentLanguageId)->getTitle();
        $parentUrl = $titleParent->getTranslation($currentLanguageId)->toUrl()->toString();
      }
    }

    // Get node IDs for districts that have the currently viewed district as a
    // sub-district.
    $parentDistrictIds = DistrictUtility::getSubdistrictParentIds($translatedNode);

    // Check if the current node itself is a parent for sub-districts.
    if (!$translatedNode->get('field_subdistricts')->isEmpty()) {
      $parentDistrictIds[] = $translatedNode->id();
    }

    // Create the navigation structure.
    foreach ($parentDistrictIds as $parentId) {
      $menuItem = 'menu_link_content:' . $parentId;

      if (!$this->entityTypeManager->getStorage('node')->load($parentId)->hasTranslation($currentLanguageId)) {
        continue;
      }
      $translatedParent = $this->entityTypeManager->getStorage('node')->load($parentId)->getTranslation($currentLanguageId);

      $subdistricts = $translatedParent->get('field_subdistricts')->referencedEntities();
      $currentUri = $this->requestStack->getCurrentRequest()->getRequestUri();

      // Set menu item.
      $navigation[$menuItem] = [
        'title' => $translatedParent->label(),
        'url' => $translatedParent->toUrl(),
        'is_expanded' => (!empty($subdistricts)),
        'is_collapsed' => FALSE,
        'in_active_trail' => ($translatedNode->id() === $parentId),
        'attributes' => new Attribute([
          'class' => [
            'menu__item',
            'menu__item--children',
            'menu__item--item-below',
          ],
        ]),
        'is_currentPage' => ($translatedParent->toUrl()->toString() === $currentUri),
      ];

      // Add parent's sub-districts.
      $navigation[$menuItem]['below'] = [];
      foreach ($subdistricts as $subdistrict) {
        /** @var \Drupal\node\NodeInterface $subdistrict */
        if (!$subdistrict->hasTranslation($currentLanguageId)) {
          continue;
        }

        // Do not show unpublished subdistrict for anonymous users.
        if (!$this->currentUser->isAuthenticated() && !$subdistrict->getTranslation($currentLanguageId)->isPublished()) {
          continue;
        }

        // Set sub-district menu item.
        $navigation[$menuItem]['below'][$subdistrict->id()] = [
          'title' => $subdistrict->getTranslation($currentLanguageId)->label(),
          'url' => $subdistrict->getTranslation($currentLanguageId)->toUrl(),
          'is_expanded' => FALSE,
          'is_collapsed' => FALSE,
          'in_active_trail' => FALSE,
          'attributes' => new Attribute([
            'class' => 'menu__item',
          ]),
          'is_currentPage' => ($subdistrict->getTranslation($currentLanguageId)->toUrl()->toString() === $currentUri),
        ];

        // Set active trail.
        if ($translatedNode->id() === $subdistrict->id()) {
          $navigation[$menuItem]['in_active_trail'] = TRUE;
          $navigation[$menuItem]['below'][$subdistrict->id()]['in_active_trail'] = TRUE;
        }
      }

      // Clear parent menu item if there is no sub-items.
      if (empty($navigation[$menuItem]['below'])) {
        unset($navigation[$menuItem]);
      }
    }

    return [
      '#theme' => 'subdistricts_navigation',
      '#navigation' => $navigation,
      '#parent_title' => $parentTitle,
      '#parent_url' => $parentUrl,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'url.path',
      'languages:language_content',
      'user.permissions',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['node_list:district']);
  }

  /**
   * Get parent node from current path.
   *
   * The parent node is determined from the current path alias so that it's
   * the previous one.
   *
   * @return \Drupal\node\NodeInterface|null
   *   Parent node.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getTitleParent(): ?NodeInterface {
    if (empty($titleParentPathAlias = dirname(
      $this->aliasManager->getAliasByPath($this->currentPath->getPath()))
    )) {
      return NULL;
    }

    if ($titleParentPath = $this->aliasManager->getPathByAlias($titleParentPathAlias)) {
      if (preg_match('/node\/(\d+)/', $titleParentPath, $matches)) {
        if ($titleParent = $this->entityTypeManager->getStorage('node')->load($matches[1])) {
          /** @var \Drupal\node\NodeInterface $titleParent */
          return $titleParent;
        }
      }
    }

    return NULL;
  }

}
