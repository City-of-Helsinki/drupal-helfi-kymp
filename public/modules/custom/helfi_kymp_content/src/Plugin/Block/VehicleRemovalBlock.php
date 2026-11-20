<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\helfi_api_base\Environment\ActiveServiceTrait;
use Drupal\helfi_api_base\Environment\EnvironmentResolverInterface;
use Drupal\helfi_hakuvahti\DrupalSettings;
use Drupal\helfi_kymp_content\VehicleRemovalFilterValues;

/**
 * Block that displays the Hakuvahti signup form.
 */
#[Block(
  id: 'kymp_vehicle_removal',
  admin_label: new TranslatableMarkup('Hakuvahti'),
)]
final class VehicleRemovalBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use ActiveServiceTrait;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly DrupalSettings $drupalSettings,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EnvironmentResolverInterface $environmentResolver,
    protected readonly VehicleRemovalFilterValues $filterValues,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritDoc}
   */
  public function build(): array {
    $reactSettings = $this->configFactory->get('react_search.settings');

    $cache = new CacheableMetadata();
    $cache->addCacheableDependency($reactSettings);
    $cache->addCacheTags(['config:helfi_api_base.environment_resolver.settings']);

    $filterValues = $this->getFilterValues();

    // Do not cache the block without filter values.
    if (!$filterValues) {
      $cache->setCacheMaxAge(0);
    }

    $build = [
      '#theme' => 'vehicle_removal',
      '#attached' => [
        'drupalSettings' => [
          'helfi_react_search' => [
            'elastic_proxy_url' => $this->getPublicElasticProxy()?->getAddress(),
            'sentry_dsn_react' => $reactSettings->get('sentry_dsn_react'),
            'postal_codes' => $filterValues['postal_codes'] ?? [],
            'resident_parking_zones' => $filterValues['resident_parking_zones'] ?? [],
          ],
        ],
        'library' => [
          'helfi_kymp_content/vehicle-removal-search',
        ],
      ],
    ];

    // Apply hakuvahti settings.
    $this->drupalSettings->applyTo($build);

    // Use the vehicle_removal config entity so its texts are isolated
    // from the shared 'default' entity.
    if (!empty($build['#attached']['drupalSettings']['hakuvahti'])) {
      $build['#attached']['drupalSettings']['hakuvahti']['apiUrl'] = Url::fromRoute(
        'helfi_hakuvahti.subscribe',
        [],
        ['query' => ['config' => 'vehicle_removal']],
      )->toString();
    }

    // Cache tags.
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Gets the postal codes and resident parking zones of Helsinki.
   *
   * @return array{postal_codes: string[], resident_parking_zones: string[]}|null
   *   The filter values, or NULL when the fetch fails.
   */
  private function getFilterValues(): ?array {
    $postalCodes = $this->filterValues->getPostalCodes();
    if ($postalCodes === NULL) {
      return NULL;
    }

    $parkingZones = $this->filterValues->getParkingZoneNames();
    if ($parkingZones === NULL) {
      return NULL;
    }

    return [
      'postal_codes' => $postalCodes,
      'resident_parking_zones' => $parkingZones,
    ];
  }

}
