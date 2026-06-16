<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Hook;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Breadcrumb alterations for Hakuvahti routes.
 */
class HakuvahtiHooks {

  private const array ROUTE_TITLE_KEYS = [
    'helfi_hakuvahti.confirm' => 'confirm_page_title',
    'helfi_hakuvahti.renew' => 'renew_page_title',
    'helfi_hakuvahti.unsubscribe' => 'unsubscribe_page_title',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * Implements hook_system_breadcrumb_alter().
   *
   * Replaces the current page's breadcrumb segment title on Hakuvahti routes
   * when a per-instance page title is configured on the matching config entity.
   */
  #[Hook('system_breadcrumb_alter')]
  public function systemBreadcrumbAlter(Breadcrumb &$breadcrumb, RouteMatchInterface $routeMatch): void {
    $routeName = $routeMatch->getRouteName();
    if (!isset(self::ROUTE_TITLE_KEYS[$routeName])) {
      return;
    }

    // Always add the cache context so the breadcrumb varies by site_id.
    $breadcrumb->addCacheContexts(['url.query_args:site_id']);

    $siteId = $this->requestStack->getCurrentRequest()?->query->get('site_id');
    if (!$siteId) {
      return;
    }

    $entities = $this->entityTypeManager
      ->getStorage('hakuvahti_config')
      ->loadByProperties(['site_id' => $siteId]);

    if (!$entities) {
      return;
    }

    $entityId = reset($entities)->id();
    $customTitle = $this->configFactory
      ->get('helfi_hakuvahti.config.' . $entityId)
      ->get(self::ROUTE_TITLE_KEYS[$routeName]) ?? '';

    if (!$customTitle) {
      return;
    }

    $links = $breadcrumb->getLinks();
    if (empty($links)) {
      return;
    }

    // Replace the last link (current page segment) with the custom title.
    $lastKey = array_key_last($links);
    $lastLink = $links[$lastKey];
    $links[$lastKey] = Link::fromTextAndUrl($customTitle, $lastLink->getUrl());

    // Breadcrumb::setLinks() throws if links are already set, so mutate the
    // internal array in-place via reflection.
    $reflLinks = new \ReflectionProperty($breadcrumb, 'links');
    $reflLinks->setValue($breadcrumb, $links);
  }

}
