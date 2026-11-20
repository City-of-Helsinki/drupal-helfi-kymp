<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\helfi_hakuvahti\Event\SubscriptionAlterEvent;
use Drupal\helfi_hakuvahti\Hakuvahti;
use Drupal\helfi_hakuvahti\HakuvahtiInterface;
use Drupal\helfi_hakuvahti\HakuvahtiRequest;
use Drupal\helfi_kymp_content\EventSubscriber\HakuvahtiSubscriptionSubscriber;
use Drupal\helfi_kymp_content\VehicleRemovalFilterValues;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\helfi_kymp_content\Traits\StreetNameIndexTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Tests the search description of vehicle removal subscriptions.
 */
#[CoversClass(HakuvahtiSubscriptionSubscriber::class)]
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class HakuvahtiSubscriptionSubscriberTest extends KernelTestBase {

  use ApiTestTrait;
  use StreetNameIndexTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'diff',
    'helfi_api_base',
    'helfi_hakuvahti',
    'helfi_kymp_content',
    'search_api',
    'search_api_db',
  ];

  /**
   * Requests sent to the hakuvahti service.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * The filter values mock.
   */
  private VehicleRemovalFilterValues&MockObject $filterValues;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createStreetNameIndex(['fi:Mannerheimintie', 'sv:Mannerheimvägen']);

    $this->filterValues = $this->createMock(VehicleRemovalFilterValues::class);
    $this->container->set(VehicleRemovalFilterValues::class, $this->filterValues);

    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('hakuvahti_config');
    $storage->create(['id' => 'default', 'label' => 'Default', 'site_id' => ''])->save();
    $storage->create(['id' => 'vehicle_removal', 'label' => 'Vehicle removal', 'site_id' => 'kymp'])->save();

    $this->config('helfi_hakuvahti.settings')
      ->set('base_url', 'https://example.com')
      ->save();
    $client = $this->createMockHistoryMiddlewareHttpClient($this->history, [new Response(200)]);
    $this->container->set(HakuvahtiInterface::class, new Hakuvahti($client, $this->container->get(ConfigFactoryInterface::class)));
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  /**
   * Encodes a vehicle removal search query.
   *
   * @param array<string, list<mixed>> $terms
   *   Values by field.
   */
  private static function elasticQuery(array $terms): string {
    $should = [];
    foreach ($terms as $field => $values) {
      $should[] = ['terms' => [$field => $values]];
    }
    $query = [
      'query' => [
        'function_score' => [
          'query' => ['bool' => ['should' => $should, 'minimum_should_match' => 1]],
          'boost_mode' => 'replace',
        ],
      ],
    ];
    return base64_encode(json_encode($query, JSON_THROW_ON_ERROR));
  }

  /**
   * Posts a subscription.
   */
  private function subscribe(string $elasticQuery, string $configId = 'vehicle_removal'): SymfonyResponse {
    $url = Url::fromRoute('helfi_hakuvahti.subscribe', options: ['query' => ['config' => $configId]]);
    return $this->processRequest($this->getMockedRequest($url->toString(), 'POST', document: [
      'email' => 'valid@email.fi',
      'sms' => '+358401234567',
      'query' => '?streets=Mannerheimintie',
      'elasticQuery' => $elasticQuery,
      'elasticQueryAtv' => TRUE,
      'searchDescription' => '<a href="https://example.org">Link</a>',
    ]));
  }

  /**
   * Gets the payload sent to the hakuvahti service.
   *
   * @return array<string, mixed>
   *   The payload.
   */
  private function getSentData(): array {
    $this->assertCount(1, $this->history);
    return json_decode((string) $this->history[0]['request']->getBody(), TRUE);
  }

  /**
   * Tests that the description is built from the known values.
   */
  public function testSearchDescription(): void {
    $this->filterValues->method('getParkingZoneNames')->willReturn(['Kamppi', 'Vallila']);

    $elasticQuery = self::elasticQuery([
      'parking_zone_names' => ['Kamppi', 'Free text'],
      'postal_codes' => ['00100', "00200\n", 100],
      'street_names' => ['Mannerheimvägen', 'Free text', 'Mannerheimintie'],
    ]);
    $response = $this->subscribe($elasticQuery);
    $this->assertEquals(200, $response->getStatusCode());

    $this->assertSame([
      'email' => 'valid@email.fi',
      'sms' => '+358401234567',
      'lang' => 'en',
      'site_id' => 'kymp',
      'query' => '?streets=Mannerheimintie',
      'elastic_query' => $elasticQuery,
      'elastic_query_atv' => TRUE,
      'search_description' => 'Mannerheimvägen, Mannerheimintie, 00100, Kamppi',
    ], $this->getSentData());
  }

  /**
   * Tests that unknown values give an empty description.
   */
  public function testUnknownValues(): void {
    $this->filterValues->method('getParkingZoneNames')->willReturn(['Kamppi']);

    $response = $this->subscribe(self::elasticQuery([
      'street_names' => ['mannerheimintie'],
      'parking_zone_names' => ['kamppi'],
    ]));
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertSame('', $this->getSentData()['search_description']);
  }

  /**
   * Tests that other configs are not altered.
   */
  public function testOtherConfig(): void {
    $this->filterValues->expects($this->never())->method('getParkingZoneNames');

    $request = new HakuvahtiRequest([
      'lang' => 'fi',
      'siteId' => 'etusivu',
      'query' => '?a=b',
      'elasticQuery' => 'not base64',
      'searchDescription' => 'Unchanged',
    ]);
    foreach (['default', NULL] as $configId) {
      $event = new SubscriptionAlterEvent($request, $configId);
      $this->container->get(EventDispatcherInterface::class)->dispatch($event);
      $this->assertSame($request, $event->getHakuvahtiRequest());
    }
  }

  /**
   * Tests that an elastic query that cannot be decoded is rejected.
   */
  public function testUndecodableQuery(): void {
    $this->assertEquals(400, $this->subscribe('not base64!')->getStatusCode());
    $this->assertCount(0, $this->history);
  }

  /**
   * Tests that streets that cannot be checked fail the subscription.
   */
  public function testStreetIndexUnavailable(): void {
    Index::load('paikkatieto_street_names')?->disable()->save();

    $response = $this->subscribe(self::elasticQuery(['street_names' => ['Mannerheimintie']]));
    $this->assertEquals(400, $response->getStatusCode());
    $this->assertCount(0, $this->history);
  }

  /**
   * Tests that zones that cannot be checked fail the subscription.
   */
  public function testParkingZonesUnavailable(): void {
    $this->filterValues->method('getParkingZoneNames')->willReturn(NULL);

    $response = $this->subscribe(self::elasticQuery(['parking_zone_names' => ['Kamppi']]));
    $this->assertEquals(400, $response->getStatusCode());
    $this->assertCount(0, $this->history);
  }

}
