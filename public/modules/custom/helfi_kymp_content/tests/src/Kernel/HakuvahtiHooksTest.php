<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\helfi_kymp_content\Hook\HakuvahtiHooks;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests HakuvahtiHooks breadcrumb alter behaviour.
 */
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class HakuvahtiHooksTest extends KernelTestBase {

  use ProphecyTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
    'helfi_hakuvahti',
    'system',
    'user',
  ];

  /**
   * Creates a HakuvahtiHooks instance with a mocked RequestStack.
   */
  private function createHooks(?string $siteId = NULL): HakuvahtiHooks {
    $requestStack = $this->prophesize(RequestStack::class);
    $request = $siteId !== NULL
      ? Request::create('/fi/test', 'GET', ['site_id' => $siteId])
      : NULL;
    $requestStack->getCurrentRequest()->willReturn($request);

    return new HakuvahtiHooks(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $requestStack->reveal(),
    );
  }

  /**
   * Creates a mocked RouteMatchInterface returning the given route name.
   */
  private function createRouteMatch(string $routeName): RouteMatchInterface {
    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getRouteName()->willReturn($routeName);
    return $routeMatch->reveal();
  }

  /**
   * Creates a Breadcrumb with three links for testing.
   */
  private function createBreadcrumb(): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addLink(Link::createFromRoute('Home', '<front>'));
    $breadcrumb->addLink(Link::createFromRoute('Parent page', '<none>'));
    $breadcrumb->addLink(Link::createFromRoute('Current page', '<none>'));
    return $breadcrumb;
  }

  /**
   * Non-hakuvahti routes are not touched and no cache context is added.
   */
  public function testNonHakuvahtiRouteIsNotAltered(): void {
    $this->installConfig(['helfi_hakuvahti']);

    $breadcrumb = $this->createBreadcrumb();
    $this->createHooks()->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch('some.other.route'),
    );

    $links = $breadcrumb->getLinks();
    $this->assertEquals('Current page', (string) $links[2]->getText());
    $this->assertNotContains('url.query_args:site_id', $breadcrumb->getCacheContexts());
  }

  /**
   * A hakuvahti route without site_id adds the cache context but leaves links.
   */
  public function testHakuvahtiRouteWithoutSiteIdAddsCacheContext(): void {
    $this->installConfig(['helfi_hakuvahti']);

    $breadcrumb = $this->createBreadcrumb();
    $this->createHooks()->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch('helfi_hakuvahti.confirm'),
    );

    $links = $breadcrumb->getLinks();
    $this->assertEquals('Current page', (string) $links[2]->getText());
    $this->assertContains('url.query_args:site_id', $breadcrumb->getCacheContexts());
  }

  /**
   * A matching config entity with a non-empty title replaces the last link.
   */
  public function testCustomTitleReplacesLastBreadcrumbLink(): void {
    $this->installConfig(['helfi_hakuvahti']);

    $this->container->get('entity_type.manager')
      ->getStorage('hakuvahti_config')
      ->create([
        'id' => 'vehicle_removal',
        'label' => 'Vehicle Removal',
        'site_id' => 'kymp',
      ])
      ->save();

    // Set the title directly in config storage so the test is not coupled to
    // whether confirm_page_title is listed in HakuvahtiConfig::config_export.
    $this->container->get('config.factory')
      ->getEditable('helfi_hakuvahti.config.vehicle_removal')
      ->set('confirm_page_title', 'Custom Confirm Title')
      ->save();

    $breadcrumb = $this->createBreadcrumb();
    $this->createHooks('kymp')->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch('helfi_hakuvahti.confirm'),
    );

    $links = $breadcrumb->getLinks();
    $this->assertCount(3, $links);
    $this->assertEquals('Custom Confirm Title', (string) $links[2]->getText());
    $this->assertContains('url.query_args:site_id', $breadcrumb->getCacheContexts());
  }

  /**
   * A config entity with an empty title leaves the breadcrumb links unchanged.
   */
  public function testEmptyCustomTitleLeavesLinksUnchanged(): void {
    $this->installConfig(['helfi_hakuvahti']);

    $this->container->get('entity_type.manager')
      ->getStorage('hakuvahti_config')
      ->create([
        'id' => 'vehicle_removal',
        'label' => 'Vehicle Removal',
        'site_id' => 'kymp',
      ])
      ->save();

    // confirm_page_title is intentionally not set — the hook should leave
    // the breadcrumb unchanged when the value is empty or absent.
    $breadcrumb = $this->createBreadcrumb();
    $this->createHooks('kymp')->systemBreadcrumbAlter(
      $breadcrumb,
      $this->createRouteMatch('helfi_hakuvahti.confirm'),
    );

    $links = $breadcrumb->getLinks();
    $this->assertEquals('Current page', (string) $links[2]->getText());
  }

}
