<?php

namespace Drupal\helfi_kymp_content\Plugin\search_api\datasource;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\TypedDataTrait;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a datasource for kartta.hel.fi.
 *
 * @SearchApiDatasource(
 *   id = "helfi_street_data_source",
 *   label = @Translation("Helfi street datasource"),
 *   description = @Translation("Datasource for street data from kartta.hel.fi."),
 * )
 */
class HelfiStreetDataSource extends DatasourcePluginBase implements DatasourceInterface {

  use TypedDataTrait;

  public const API_URL = 'https://kartta.hel.fi/ws/geoserver/avoindata/wfs';

  /**
   * The client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $client;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('http_client');
    $instance->logger = $container->get('logger.channel.helfi_kymp_content');
    return $instance;
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
    $query = http_build_query([
      'request' => 'GetFeature',
      'service' => 'WFS',
      'version' => '1.1.0',
      'typeName' => 'avoindata:YLRE_Katualue_alue',
      'propertyname' => 'avoindata:kadun_nimi,avoindata:kayttotarkoitus,avoindata:yllapitoluokka,avoindata:pituus',
    ]);
    $uri = sprintf('%s?%s', 'https://kartta.hel.fi/ws/geoserver/avoindata/wfs', $query);

    try {
      $content = $this->client->request('GET', $uri);
      $xmlResult = $content->getBody()->getContents();
      if (!$xmlResult) {
        return [];
      }
    }
    catch (\Exception $e) {
      $this->logger->error("Errors while fetching street data from kartta.hel.fi: {$e->getMessage()}");
      return [];
    }

    libxml_use_internal_errors(TRUE);
    $doc = new \DOMDocument(encoding: 'UTF-8');
    $doc->loadXML($xmlResult);
    $errors = libxml_get_errors();

    if ($errors) {
      $this->logger->error('Errors while parsing street data xml string.');
      return [];
    }

    $data = [];
    foreach ($doc->firstChild->firstChild->childNodes->getIterator() as $street_data) {
      $id = NULL;
      $single_street = [];

      foreach ($street_data->childNodes->getIterator() as $field) {
        switch ($field->nodeName) {
          case 'avoindata:katualue_id':
            $id = $field->nodeValue;
            $single_street['id'] = $ids && $id && in_array($id, $ids) ? $id : NULL;
            break;

          case 'avoindata:kadun_nimi':
            $single_street['street_name'] = $field->nodeValue;
            break;

          case 'avoindata:pituus':
            $single_street['length'] = $field->nodeValue;
            break;

          case 'avoindata:yllapitoluokka':
            // Turn field value from III or II to 3 or 2 etc.
            $single_street['maintenance_class'] = strlen($field->nodeValue);
            break;
        }
      }

      if ($ids && $id && !in_array($id, $ids)) {
        continue;
      }

      $street_data_definition = $this->getTypedDataManager()->createDataDefinition('street_data');
      /** @var \Drupal\Core\TypedData\ComplexDataInterface $street_data */
      $street_data = $this->getTypedDataManager()->create($street_data_definition);
      $street_data->setValue($single_street);
      $data[$id] = $street_data;
    }

    return $data;
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
    $property_definition = [];

    $property_definition['id'] = DataDefinition::create('integer')
      ->setLabel('id')
      ->setRequired(TRUE);
    $property_definition['street_name'] = DataDefinition::create('string')
      ->setLabel('Street name')
      ->addConstraint('Range', ['min' => 0, 'max' => 255])
      ->setRequired(TRUE);
    $property_definition['length'] = DataDefinition::create('integer')
      ->setLabel('Length')
      ->setRequired(TRUE);
    $property_definition['maintenance_class'] = DataDefinition::create('integer')
      ->setLabel('Maintenance class')
      ->addConstraint('Range', ['min' => 0, 'max' => 5])
      ->setRequired(TRUE);

    return $property_definition;
  }

}
