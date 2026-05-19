<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\search_api\datasource;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\Utility\Error;
use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\helfi_kymp_content\Paikkatieto\Exception;
use Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiDatasource;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a datasource for MobileNote parking sign data.
 */
#[SearchApiDatasource(
  id: "mobilenote_data_source",
  label: new TranslatableMarkup("MobileNote datasource"),
  description: new TranslatableMarkup("Datasource for MobileNote parking sign data.")
)]
final class MobileNoteDataSource extends DatasourcePluginBase implements DatasourceInterface {

  /**
   * The MobileNote data service.
   */
  protected MobileNoteDataService $dataService;

  /**
   * Logger.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
    $instance->dataService = $container->get(MobileNoteDataService::class);
    $instance->logger = $container->get('logger.channel.helfi_kymp_content');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemId(ComplexDataInterface $item): null|string {
    return $item['id'] ?? NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function load($id) {
    $items = $this->loadMultiple([$id]);
    return $items ? reset($items) : NULL;
  }

  /**
   * {@inheritDoc}
   *
   * @phpstan-return string[]|null
   */
  public function getItemIds($page = NULL): ?array {
    // Fast fetch (no enrichment).
    $ids = array_keys($this->dataService->getMobileNoteData());

    // NULL = "tracking complete, no more pages".
    if (empty($ids)) {
      return NULL;
    }

    // Only return items on first page (page 0 or NULL).
    if ($page !== NULL && $page > 0) {
      return NULL;
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids): array {
    if (empty($ids)) {
      return [];
    }

    // Fetch raw data (fast).
    $all = $this->dataService->getMobileNoteData();
    $items = [];

    foreach ($ids as $id) {
      if (isset($all[$id])) {
        $items[$id] = $all[$id];
      }
    }

    // Enrich each item with nearby streets. Drop individual items
    // that fail enrichment so a single bad item doesn't fail the batch.
    foreach ($items as $id => $item) {
      try {
        $this->dataService->fetchNearbyStreets($item);
      }
      catch (\InvalidArgumentException | Exception $e) {
        Error::logException($this->logger, $e);
        unset($items[$id]);
      }
    }

    return $items;
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
    return MobileNoteData::propertyDefinitions();
  }

}
