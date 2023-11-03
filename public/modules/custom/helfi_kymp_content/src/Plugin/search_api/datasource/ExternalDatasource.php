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
 *   id = "external_source",
 *   label = @Translation("External datasource"),
 *   description = @Translation("Datasource for street data from kartta.hel.fi."),
 * )
 */
class ExternalDatasource extends DatasourcePluginBase implements DatasourceInterface {

  use TypedDataTrait;

  public const API_URL = 'https://kartta.hel.fi/ws/geoserver/avoindata/wfs';

  protected ClientInterface $client;

  protected LoggerInterface $logger;

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
      // log
    }

    libxml_use_internal_errors(true);
    $doc = new \DOMDocument(encoding: 'UTF-8');
    $doc->loadXML($xmlResult);
    $errors = libxml_get_errors();

    if ($errors) {
      // log ?
    }

    $street_data_definition = $this->typedDataManager->createDataDefinition('street_data');

    $data = [];
    foreach ($doc->firstChild->firstChild->childNodes->getIterator() as $street_data) {
      $single_street = [];
      foreach ($street_data->childNodes->getIterator() as $field) {
        switch($field->nodeName) {
          case 'avoindata:katualue_id':
            $id = $field->nodeValue;
            $single_street['id'] = $id;
            break;
          case 'avoindata:kadun_nimi':
            $single_street['street_name'] = $field->nodeValue;
            break;
          case 'avoindata:yllapitoluokka':
            // Turn field value from III or II to 3 or 2 etc.
            $single_street['maintenance_class'] = strlen($field->nodeValue);
            break;
        }
      }

      if (!$id) {
        continue;
      }

      $street_data = $this->typedDataManager->create($street_data_definition);
      $street_data->setValue($single_street);
      $data[$id] = $street_data;
    }

    // Todo: if id is set
    if ($ids) {
      $x = [];
      foreach($ids as $id){
        $x[$id] = $data[$id];
      }
      return $x;
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemLanguage(ComplexDataInterface $item): string {
    return  LanguageInterface::LANGCODE_NOT_SPECIFIED;
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
    $property_definition['maintenance_class'] = DataDefinition::create('integer')
      ->setLabel('Maintenance class')
      ->addConstraint('Range', ['min' => 0, 'max' => 5])
      ->setRequired(TRUE);

    return $property_definition;
  }

}
