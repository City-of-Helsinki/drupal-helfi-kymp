<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\search_api\datasource;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a datasource for MobileNote parking sign data.
 *
 * @SearchApiDatasource(
 *   id = "mobilenote_data_source",
 *   label = @Translation("MobileNote datasource"),
 *   description = @Translation("Datasource for MobileNote parking sign data."),
 * )
 */
class MobileNoteDataSource extends DatasourcePluginBase implements DatasourceInterface {

  /**
   * The MobileNote data service.
   */
  protected MobileNoteDataService $dataService;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->dataService = $container->get(MobileNoteDataService::class);
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
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids): array {
    /** @var array<string, \Drupal\Core\TypedData\ComplexDataInterface> $mobileNoteData */
    $mobileNoteData = $this->dataService->getMobileNoteData();

    if ($ids) {
      return array_intersect_key($mobileNoteData, array_flip($ids));
    }

    return $mobileNoteData;
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
