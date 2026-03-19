<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\helfi_kymp_content\Hook\MobileNoteCronHooks;
use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSet;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;

/**
 * Tests MobileNoteCronHooks.
 */
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class MobileNoteCronHooksTest extends KernelTestBase {

  use ProphecyTrait;
  use ApiTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
    'search_api',
    'system',
    'user',
  ];

  /**
   * Approximately 2026-02-04.
   *
   * Fixture items relative to this:
   * - 68458: expired (valid_to 2026-01-29)
   * - 68755: active (valid_to 2026-03-08)
   * - 68761: active (valid_to 2026-03-08)
   * - 68770: active (valid_to 2026-04-15)
   */
  private const int CURRENT_TIME = 1770156000;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Configure MobileNote API settings.
    $this->config('helfi_kymp_content.settings')
      ->set('wfs_url', 'https://example.com/wfs')
      ->set('wfs_username', 'user')
      ->set('wfs_password', 'pass')
      ->save();

    // Mock HTTP client with fixture data.
    $client = $this->createMockHttpClient([
      new Response(body: file_get_contents(
        dirname(__DIR__, 2) . '/fixtures/mobilenote.json'
      )),
    ]);
    $this->container->set('http_client', $client);
  }

  /**
   * Creates a mock Index with query() returning given stale item IDs.
   *
   * @param array $staleItemIds
   *   Raw item IDs to return from the index query (simulating stale entries).
   *
   * @return \Prophecy\Prophecy\ObjectProphecy
   *   The Index prophecy (call ->reveal() when passing to code under test).
   */
  private function createMockIndex(array $staleItemIds = []): object {
    $index = $this->prophesize(Index::class);

    $queryProphecy = $this->prophesize(QueryInterface::class);
    $queryMock = $queryProphecy->reveal();

    // Build result set with stale items from the index.
    $resultSet = new ResultSet($queryMock);
    foreach ($staleItemIds as $id) {
      $item = $this->prophesize(ItemInterface::class);
      $item->getId()->willReturn("mobilenote_data_source/$id");
      $resultSet->addResultItem($item->reveal());
    }

    $queryProphecy->addCondition('valid_to', self::CURRENT_TIME * 1000, '<')
      ->willReturn($queryMock);
    $queryProphecy->range(0, 100)->willReturn($queryMock);
    $queryProphecy->execute()->willReturn($resultSet);

    $index->query()->willReturn($queryMock);

    return $index;
  }

  /**
   * Tests first cron run: all active items are new.
   */
  public function testFirstCronRun(): void {
    $index = $this->createMockIndex();

    // All 3 active items should be inserted.
    $index->trackItemsInserted('mobilenote_data_source', Argument::that(function ($ids) {
      sort($ids);
      return $ids === [
        'ppoytakirjaExtranet.68755',
        'ppoytakirjaExtranet.68761',
        'ppoytakirjaExtranet.68770',
      ];
    }))->shouldBeCalled();

    // The expired item should be deleted.
    $index->trackItemsDeleted('mobilenote_data_source', ['ppoytakirjaExtranet.68458'])
      ->shouldBeCalled();

    // No updates on first run.
    $index->trackItemsUpdated(Argument::cetera())->shouldNotBeCalled();

    $hooks = $this->createSut($index->reveal());
    $hooks->cron();
  }

  /**
   * Tests subsequent cron run with known items state.
   *
   * Exercises all tracking paths in a single run:
   * - 68755: known with same updated_at -> skipped (no tracking)
   * - 68761: known with different updated_at -> trackItemsUpdated
   * - 68770: not in known items -> trackItemsInserted
   * - 68458: expired in API data -> trackItemsDeleted
   * - 99999: stale item from index query -> trackItemsDeleted.
   */
  public function testSubsequentCronRun(): void {
    // Fetch actual data to get real updated_at timestamps.
    $dataService = $this->container->get(MobileNoteDataService::class);
    $data = $dataService->getMobileNoteData();

    // Pre-populate known items:
    // - 68755 with matching updated_at -> unchanged
    // - 68761 with a different value -> will be detected as updated
    // - 68770 absent -> will be detected as new.
    $state = $this->container->get(StateInterface::class);
    $state->set(MobileNoteCronHooks::STATE_KNOWN_ITEMS, [
      'ppoytakirjaExtranet.68755' => $data['ppoytakirjaExtranet.68755']->get('updated_at')->getValue(),
      'ppoytakirjaExtranet.68761' => 0,
    ]);

    // Index query returns a stale item that's no longer in the API.
    $index = $this->createMockIndex(['ppoytakirjaExtranet.99999']);

    // Only the genuinely new item should be inserted.
    $index->trackItemsInserted('mobilenote_data_source', ['ppoytakirjaExtranet.68770'])
      ->shouldBeCalled();

    // The item with changed updated_at should be re-indexed.
    $index->trackItemsUpdated('mobilenote_data_source', ['ppoytakirjaExtranet.68761'])
      ->shouldBeCalled();

    // Both the API-expired and index-stale items should be deleted.
    $index->trackItemsDeleted('mobilenote_data_source', Argument::that(function ($ids) {
      sort($ids);
      return $ids === [
        'ppoytakirjaExtranet.68458',
        'ppoytakirjaExtranet.99999',
      ];
    }))->shouldBeCalled();

    $hooks = $this->createSut($index->reveal());
    $hooks->cron();
  }

  /**
   * Creates the MobileNoteCronHooks instance with mocked dependencies.
   */
  private function createSut(object $indexMock): MobileNoteCronHooks {
    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->load('mobilenote_data')->willReturn($indexMock);

    $etm = $this->prophesize(EntityTypeManagerInterface::class);
    $etm->getStorage('search_api_index')->willReturn($storage->reveal());

    $time = $this->prophesize(TimeInterface::class);
    $time->getRequestTime()->willReturn(self::CURRENT_TIME);

    return new MobileNoteCronHooks(
      $this->container->get(StateInterface::class),
      $etm->reveal(),
      $time->reveal(),
      $this->prophesize(LoggerInterface::class)->reveal(),
      $this->container->get(MobileNoteDataService::class),
    );
  }

}
