<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\search_api\datasource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Utility\Error;
use Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient;
use Drupal\helfi_kymp_content\Plugin\DataType\PaikkatietoStreetName;
use Drupal\search_api\Attribute\SearchApiDatasource;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a datasource for Paikkatieto street names.
 */
#[SearchApiDatasource(
  id: 'paikkatieto_street_name_source',
  label: new TranslatableMarkup('Paikkatieto street name datasource'),
  description: new TranslatableMarkup('Street names from Paikkatieto API for autocomplete.'),
)]
class PaikkatietoStreetNameDataSource extends DatasourcePluginBase implements DatasourceInterface {

  /**
   * Paikkatieto API client.
   */
  protected PaikkatietoClient $client;

  /**
   * Typed data manager.
   */
  protected TypedDataManagerInterface $typedDataManager;

  /**
   * Logger.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritDoc}
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get(PaikkatietoClient::class);
    $instance->typedDataManager = $container->get(TypedDataManagerInterface::class);
    $instance->logger = $container->get('logger.channel.helfi_kymp_content');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemIds($page = NULL) {
    // API pages are 1-based, datasource pages are 0-based.
    $apiPage = ($page ?? 0) + 1;

    try {
      $results = $this->client->fetchStreetNamesByPage($apiPage);
    }
    catch (\InvalidArgumentException $e) {
      Error::logException($this->logger, $e);
      return NULL;
    }

    if ($results === NULL) {
      return NULL;
    }

    $ids = [];
    foreach ($results as $entry) {
      $ids[] = "{$entry['language']}:{$entry['name']}";
    }

    // The API returns addresses, not unique street names, so the same
    // street name appears multiple times per page. Duplicates within a
    // single batch cause integrity constraint violations in the tracker.
    return array_unique($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getItemId(ComplexDataInterface $item): null|string {
    return $item->get('id')->getString();
  }

  /**
   * {@inheritDoc}
   *
   * Item content is encoded in the ID. No network requests needed.
   */
  public function load($id): ?ComplexDataInterface {
    [$language, $name] = explode(':', $id, 2);
    $definition = $this->typedDataManager->createDataDefinition('paikkatieto_street_name');
    $typedData = $this->typedDataManager->create($definition);
    assert($typedData instanceof ComplexDataInterface);
    $typedData->setValue([
      'id' => $id,
      'street_name' => $name,
      'language' => $language,
    ]);

    return $typedData;
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string> $ids
   */
  public function loadMultiple(array $ids): array {
    $items = [];

    foreach ($ids as $id) {
      $items[$id] = $this->load($id);
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemLanguage(ComplexDataInterface $item): string {
    return $item->get('language')->getString();
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(): array {
    return PaikkatietoStreetName::propertyDefinitions();
  }

}
