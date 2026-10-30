<?php

namespace Drupal\helfi_kymp_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Http\ClientFactory;
use Drupal\helfi_api_base\Environment\ActiveServiceTrait;
use Drupal\helfi_api_base\Environment\EnvironmentEnum;
use Drupal\helfi_api_base\Environment\EnvironmentResolverInterface;
use Drupal\helfi_api_base\Environment\Project;
use Drupal\helfi_api_base\Environment\ServiceEnum;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder as ElasticClientBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class MobileNoteController extends ControllerBase {

  use ActiveServiceTrait;

  public function __construct(
    # [AutowireServiceClosure('logger.channel.helfi_kymp_content')]
    # private LoggerChannelInterface $logger,
    protected readonly ClientFactory $clientFactory,
    protected readonly EnvironmentResolverInterface $environmentResolver,
  ) {

  }


  public function getMobileNoteData(Request $request): JsonResponse {
    $streetAddress = '';
    $local = $this->getElastic();
    $client = $this->getElasticClient();
    try {
      $result = $client->search(
        [
          'index' => 'mobilenote_data',
          // 'body' => $this->getElasticQuery(['00100'], [])
          'body' => [
            'size' => 3,
            'from' => 0,
            'query' => [
              'bool' => [
                'should' => [
                  ['term' => ['postal_codes' => '00100']],
                ],
                'minimum_should_match' => 1,
              ],
            ],
            /*
            // Return only specific fields.
            'fields' => [
              'street_names'
            ],
            '_source' => false
            */
          ],
        ]
      )?->asArray();
    }
    catch (\Exception $e) {
      // $x = 1;
      // $this->logger->error($e->getMessage());
    }

    return new JsonResponse($result['hits']['hits'] ?? []);
  }

  private function getElasticClient(): Client {
    // @todo Elastic client buildingvia service.
    // @todo Correct elastic url.
    return ElasticClientBuilder::create()
      // ->setHosts(['https://elastic-proxy-helfi-kymp.docker.so'])
      // ->setHosts([$this->getPublicElasticProxy()?->getAddress()])
      ->setHosts(['http://helfi-kymp-elastic-proxy:8080'])
      ->setHttpClient($this->clientFactory->fromOptions([
        'timeout' => 5,
        'connect_timeout' => 1,
        // Elasticsearch uses a self-signed certificate.
        'verify' => false,//!$hasElasticAccess,
      ]))
      ->build();
  }

  private function getElasticQuery(array $postalCodes, array $parkingZones): array {
    return [
      'size' => 30,
      'from' => 0,
      'query' => [
        'bool' => [
          'should' => [
            ["terms" => ['postal_codes' => $postalCodes]],
            ["terms" => ['parking_zones' => $parkingZones]],
          ],
          'minimum_should_match' => 1,
        ],
      ],
      /*
      // Return only specific fields.
      'fields' => [
        'street_names'
      ],
      '_source' => false
      */
    ];
  }

}
