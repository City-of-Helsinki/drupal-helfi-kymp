<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\helfi_hakuvahti\DrupalSettings;

/**
 * Block that displays the Hakuvahti signup form.
 */
#[Block(
  id: 'kymp_vehicle_removal',
  admin_label: new TranslatableMarkup('Hakuvahti'),
)]
final class VehicleRemovalBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly DrupalSettings $drupalSettings,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritDoc}
   */
  public function build(): array {
    $proxySettings = $this->configFactory->get('elastic_proxy.settings');
    $reactSettings = $this->configFactory->get('react_search.settings');

    $cache = new CacheableMetadata();
    $cache->addCacheableDependency($proxySettings);
    $cache->addCacheableDependency($reactSettings);

    $build = [
      '#theme' => 'vehicle_removal',
      '#attached' => [
        'drupalSettings' => [
          'helfi_react_search' => [
            'elastic_proxy_url' => $proxySettings->get('elastic_proxy_url'),
            'sentry_dsn_react' => $reactSettings->get('sentry_dsn_react'),
          ],
        ],
        'library' => [
          'helfi_kymp_content/vehicle-removal-search',
        ],
      ],
    ];

    // Apply hakuvahti settings.
    $this->drupalSettings->applyTo($build);

    // Cache tags.
    $cache->applyTo($build);

    return $build;
  }

}
