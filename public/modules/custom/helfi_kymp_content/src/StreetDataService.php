<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content;

use Drupal\Core\TypedData\TypedDataManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service for fetching street data from kartta.hel.fi.
 */
class StreetDataService {

  public const API_URL = 'https://kartta.hel.fi/ws/geoserver/avoindata/wfs';

  /**
   * Constructs a new StreetDataService instance.
   */
  public function __construct(
    protected readonly ClientInterface $client,
    protected readonly TypedDataManagerInterface $typedDataManager,
    #[Autowire(service: 'logger.channel.helfi_kymp_content')]
    protected readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Gets street data.
   *
   * @return array<int|string, \Drupal\Core\TypedData\ComplexDataInterface>
   *   Street data.
   */
  public function getStreetData(): array {
    $query = http_build_query([
      'request' => 'GetFeature',
      'service' => 'WFS',
      'version' => '1.1.0',
      'typeName' => 'avoindata:YLRE_Katualue_alue',
      'propertyname' => 'avoindata:kadun_nimi,avoindata:yllapitoluokka,avoindata:pituus',
    ]);
    $uri = sprintf('%s?%s', self::API_URL, $query);

    try {
      $content = $this->client->request('GET', $uri);
      $xmlResult = $content->getBody()->getContents();
      if (!$xmlResult) {
        return [];
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error("Errors while fetching street data from kartta.hel.fi: {$e->getMessage()}");
      return [];
    }

    $internal_errors = libxml_use_internal_errors(TRUE);
    $doc = new \DOMDocument(encoding: 'UTF-8');
    $doc->preserveWhiteSpace = FALSE;
    $doc->loadXML($xmlResult);
    $errors = libxml_get_errors();
    libxml_use_internal_errors($internal_errors);

    if ($errors) {
      $this->logger->error('Errors while parsing street data xml string.');
      return [];
    }

    $data = [];
    foreach ($doc->firstChild->firstChild->childNodes->getIterator() as $street_data) {
      if (!$street_data instanceof \DOMElement) {
        continue;
      }

      $id = $street_data->getAttribute('gml:id');
      if (!$id) {
        continue;
      }

      $single_street = [
        'id' => $id,
      ];

      foreach ($street_data->childNodes->getIterator() as $field) {
        switch ($field->nodeName) {
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

      $street_data_definition = $this->typedDataManager->createDataDefinition('street_data');
      /** @var \Drupal\Core\TypedData\ComplexDataInterface $street_data */
      $street_data = $this->typedDataManager->create($street_data_definition);
      $street_data->setValue($single_street);
      $data[$id] = $street_data;
    }

    return $data;
  }

}
