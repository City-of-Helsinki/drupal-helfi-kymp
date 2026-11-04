<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\helfi_api_base\Environment\ActiveServiceTrait;
use Drupal\helfi_api_base\Environment\EnvironmentResolverInterface;
use Drupal\helfi_api_base\ServiceMap\ResidentParkingZoneServiceInterface;
use Drupal\helfi_api_base\ServiceMap\ServiceMapInterface;
use Drupal\helfi_hakuvahti\DrupalSettings;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Block that displays the Hakuvahti signup form.
 */
#[Block(
  id: 'kymp_vehicle_removal',
  admin_label: new TranslatableMarkup('Hakuvahti'),
)]
final class VehicleRemovalBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use ActiveServiceTrait;

  private const string FILTER_VALUES_CID = 'helfi_kymp_content:vehicle_removal_filter_values';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly DrupalSettings $drupalSettings,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EnvironmentResolverInterface $environmentResolver,
    protected readonly ServiceMapInterface $serviceMap,
    protected readonly ResidentParkingZoneServiceInterface $parkingZoneService,
    #[Autowire(service: 'cache.default')]
    protected readonly CacheBackendInterface $cache,
    #[Autowire(service: 'logger.channel.helfi_kymp_content')]
    protected readonly LoggerInterface $logger,
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
    if ($cached = $this->cache->get(self::FILTER_VALUES_CID)) {
      return $cached->data;
    }

    try {
      $postalCodes = $this->serviceMap->getPostalCodes();
      $parkingZones = $this->parkingZoneService->getParkingZoneNames();
    }
    catch (GuzzleException $e) {
      Error::logException($this->logger, $e);
      return NULL;
    }

    $postalCodes = array_unique($postalCodes);
    $parkingZones = array_unique($parkingZones);
    sort($postalCodes);
    sort($parkingZones);

    $values = [
      'postal_codes' => $postalCodes,
      'resident_parking_zones' => $parkingZones,
    ];
    $this->cache->set(self::FILTER_VALUES_CID, $values);

    return $values;
  }

}
