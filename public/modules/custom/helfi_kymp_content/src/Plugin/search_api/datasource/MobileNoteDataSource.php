<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\search_api\datasource;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiDatasource;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;
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

  /**
   * Constructs a new MobileNoteDataSource instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\helfi_kymp_content\MobileNoteDataService $dataService
   *   The MobileNote data service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected MobileNoteDataService $dataService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(MobileNoteDataService::class)
    );
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
   */
  public function getItemIds($page = NULL) {
    // Fast fetch (no enrichment).
    return array_keys($this->dataService->getMobileNoteData(FALSE));
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids): array {
    // Fetch raw data (fast).
    $all = $this->dataService->getMobileNoteData(FALSE);
    $items = [];

    foreach ($ids as $id) {
      if (isset($all[$id])) {
        $items[$id] = $all[$id];
      }
    }

    // Fetch streets only for the loaded batch.
    $this->dataService->fetchNearbyStreets($items);

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
