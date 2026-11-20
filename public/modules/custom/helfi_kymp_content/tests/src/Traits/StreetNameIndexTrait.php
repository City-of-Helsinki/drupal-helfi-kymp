<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Traits;

use Drupal\helfi_kymp_content\Paikkatieto\PaikkatietoClient;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;

/**
 * Creates the street name index on a database server.
 */
trait StreetNameIndexTrait {

  /**
   * Creates the street name index with the given items.
   *
   * @param list<string> $ids
   *   Item ids, '{langcode}:{name}'.
   */
  protected function createStreetNameIndex(array $ids): IndexInterface {
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('search_api_task');

    $client = $this->createMock(PaikkatietoClient::class);
    $client->method('fetchStreetNamesByPage')->willReturn(NULL);
    $this->container->set(PaikkatietoClient::class, $client);

    Server::create([
      'id' => 'database_server',
      'name' => 'Database server',
      'backend' => 'search_api_db',
      'backend_config' => [
        'database' => 'default:default',
        'min_chars' => 3,
        'matching' => 'words',
      ],
    ])->save();

    $index = Index::create([
      'id' => 'paikkatieto_street_names',
      'name' => 'Paikkatieto street names',
      'server' => 'database_server',
      'datasource_settings' => [
        'paikkatieto_street_name_source' => [],
      ],
      'tracker_settings' => [
        'default' => [],
      ],
      'field_settings' => [
        'id' => [
          'label' => 'ID',
          'datasource_id' => 'paikkatieto_street_name_source',
          'property_path' => 'id',
          'type' => 'string',
        ],
        'street_name' => [
          'label' => 'Street name',
          'datasource_id' => 'paikkatieto_street_name_source',
          'property_path' => 'street_name',
          'type' => 'text',
        ],
      ],
      'options' => [
        'index_directly' => FALSE,
      ],
    ]);
    $index->save();

    if ($ids) {
      $index->trackItemsInserted('paikkatieto_street_name_source', $ids);
      $index->indexItems();
    }

    return $index;
  }

}
