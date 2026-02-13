<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\search_api\datasource;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\helfi_kymp_content\Plugin\DataType\StreetData;
use Drupal\helfi_kymp_content\StreetDataService;
use Drupal\search_api\Attribute\SearchApiDatasource;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a datasource for kartta.hel.fi.
 */
#[SearchApiDatasource(
  id: 'helfi_street_data_source',
  label: new TranslatableMarkup('Helfi street datasource'),
  description: new TranslatableMarkup('Datasource for street data from kartta.hel.fi.'),
)]
class HelfiStreetDataSource extends DatasourcePluginBase implements DatasourceInterface {

  /**
   * The client.
   */
  protected StreetDataService $client;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get(StreetDataService::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemIds($page = NULL) {
    // No pagination.
    if ($page && $page > 0) {
      return NULL;
    }

    return array_keys($this->loadMultiple([]));
  }

  /**
   * {@inheritdoc}
   */
  public function getItemId(ComplexDataInterface $item): null|string {
    return $item['id'];
  }

  /**
   * {@inheritDoc}
   */
  public function load($id) {
    $items = $this->loadMultiple([$id]);
    return $items ? reset($items) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids): array {
    $streetData = $this->client->getStreetData();

    if ($ids) {
      return array_intersect_key($streetData, array_flip($ids));
    }

    return $streetData;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemLanguage(ComplexDataInterface $item): string {
    return LanguageInterface::LANGCODE_NOT_SPECIFIED;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(): array {
    return StreetData::propertyDefinitions();
  }

}
