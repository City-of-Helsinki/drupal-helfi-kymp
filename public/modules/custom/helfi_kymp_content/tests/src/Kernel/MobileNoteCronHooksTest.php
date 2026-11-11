<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\State\StateInterface;
use Drupal\helfi_kymp_content\Hook\MobileNoteCronHooks;
use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSet;
use Drupal\search_api\Tracker\TrackerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests MobileNoteCronHooks.
 */
#[Group('helfi_kymp_content')]
#[CoversClass(MobileNoteCronHooks::class)]
#[RunTestsInSeparateProcesses]
class MobileNoteCronHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
    'helfi_api_base',
    'search_api',
    'system',
    'user',
    'diff',
  ];

  /**
   * Midnight 2026-02-04 in Helsinki.
   */
  private const int CURRENT_TIME = 1770156000;

  /**
   * A valid_to value for items that are shown in the search.
   */
  private const int ACTIVE = self::CURRENT_TIME + 86400;

  /**
   * A valid_to value for items that ended two days ago.
   */
  private const int ENDED = self::CURRENT_TIME - 2 * 86400;

  /**
   * The start of the retention period.
   */
  private const int RETENTION_START = self::CURRENT_TIME - 365 * 86400;

  /**
   * The tracked item IDs by tracking method, for the last run.
   *
   * @var array<string, list<string>>
   */
  private array $tracked = [];

  /**
   * The number of items waiting to be indexed.
   */
  private int $remainingItems = 0;

  /**
   * The IDs the index query returns as expired.
   *
   * @var list<string>
   */
  private array $expiredIds = [];

  /**
   * The current time.
   */
  private int $currentTime = self::CURRENT_TIME;

  /**
   * Tests that new items are tracked before the ended ones.
   */
  public function testFirstRun(): void {
    $items = [
      $this->item('active.1', self::ACTIVE),
      // Shown until a day after valid_to, so this is still active.
      $this->item('active.2', self::CURRENT_TIME - 86400),
      $this->item('ended.1', self::ENDED),
    ];

    $this->runCron($items);

    $this->assertTracked(['active.1', 'active.2'], 'trackItemsInserted');
    $this->assertEquals(['active.1', 'active.2'], array_keys($this->getKnownItems()));

    // The ended item is tracked on the next run, which has no new items.
    $this->runCron($items);

    $this->assertTracked(['ended.1'], 'trackItemsInserted');
    $this->assertEquals(['active.1', 'active.2', 'ended.1'], array_keys($this->getKnownItems()));
  }

  /**
   * Tests that the known items are read from state after the upgrade.
   */
  public function testLegacyKnownItems(): void {
    $state = $this->container->get(StateInterface::class);
    $state->set('helfi_kymp_content.mobilenote_known_items', ['unchanged' => 100, 'updated' => 100]);

    $this->runCron([
      $this->item('unchanged', self::ACTIVE, 100),
      $this->item('updated', self::ACTIVE, 200),
    ]);

    $this->assertTracked([], 'trackItemsInserted');
    $this->assertTracked(['updated'], 'trackItemsUpdated');
    $this->assertEquals(['unchanged' => 100, 'updated' => 200], $this->getKnownItems());
    $this->assertNull($state->get('helfi_kymp_content.mobilenote_known_items'));
  }

  /**
   * Tests that ended items are tracked in chunks, latest first.
   */
  public function testBackfillIsLimited(): void {
    $total = MobileNoteCronHooks::MAX_BACKFILL_PER_RUN + 10;
    $items = [];
    for ($i = 1; $i <= $total; $i++) {
      $items[] = $this->item("ended.$i", self::ENDED - $i * 3600);
    }

    $this->runCron($items);

    $latest = array_map(static fn (int $i) => "ended.$i", range(1, MobileNoteCronHooks::MAX_BACKFILL_PER_RUN));
    $this->assertTracked($latest, 'trackItemsInserted');

    $this->runCron($items);

    $rest = array_map(static fn (int $i) => "ended.$i", range(MobileNoteCronHooks::MAX_BACKFILL_PER_RUN + 1, $total));
    $this->assertTracked($rest, 'trackItemsInserted');
    $this->assertCount($total, $this->getKnownItems());
  }

  /**
   * Tests that ended items wait while other items are being indexed.
   */
  public function testBackfillWaitsForIndexing(): void {
    $items = [
      $this->item('active.1', self::ACTIVE),
      $this->item('ended.1', self::ENDED),
    ];
    $this->remainingItems = 5;

    // New items are tracked even when the queue is not empty.
    $this->runCron($items);
    $this->assertTracked(['active.1'], 'trackItemsInserted');

    $this->runCron($items);
    $this->assertTracked([], 'trackItemsInserted');

    $this->remainingItems = 0;
    $this->runCron($items);
    $this->assertTracked(['ended.1'], 'trackItemsInserted');
  }

  /**
   * Tests that ended items wait for a run without updated items.
   */
  public function testBackfillWaitsForUpdates(): void {
    $this->setKnownItems(['active.1' => 100]);
    $items = [
      $this->item('active.1', self::ACTIVE, 200),
      $this->item('ended.1', self::ENDED),
    ];

    $this->runCron($items);
    $this->assertTracked(['active.1'], 'trackItemsUpdated');
    $this->assertTracked([], 'trackItemsInserted');

    $this->runCron($items);
    $this->assertTracked([], 'trackItemsUpdated');
    $this->assertTracked(['ended.1'], 'trackItemsInserted');
  }

  /**
   * Tests updated items.
   */
  public function testUpdatedItems(): void {
    $this->setKnownItems([
      'unchanged' => 100,
      'updated' => 100,
      'no.update.time' => NULL,
      'removed' => 100,
    ]);

    $this->runCron([
      $this->item('unchanged', self::ACTIVE, 100),
      $this->item('updated', self::ACTIVE, 200),
      $this->item('no.update.time', self::ACTIVE, NULL),
    ]);

    $this->assertTracked(['updated'], 'trackItemsUpdated');
    $this->assertTracked([], 'trackItemsInserted');
    // Items the API no longer returns are not deleted: they are kept until
    // they expire.
    $this->assertTracked([], 'trackItemsDeleted');
    $this->assertEquals(['unchanged' => 100, 'updated' => 200, 'no.update.time' => NULL], $this->getKnownItems());
  }

  /**
   * Tests that items that ended before the retention period are deleted.
   */
  public function testExpiredItems(): void {
    $this->expiredIds = ['expired.1', 'expired.2'];

    $this->runCron([$this->item('active.1', self::ACTIVE)]);

    $this->assertTracked(['expired.1', 'expired.2'], 'trackItemsDeleted');
  }

  /**
   * Tests that a failed request keeps the known items.
   */
  public function testEmptyResponse(): void {
    $this->setKnownItems(['known' => 100]);
    $this->expiredIds = ['expired.1'];

    $this->runCron([]);

    // Expired items are deleted regardless of the API.
    $this->assertTracked(['expired.1'], 'trackItemsDeleted');
    $this->assertTracked([], 'trackItemsInserted');
    $this->assertEquals(['known' => 100], $this->getKnownItems());
  }

  /**
   * Tests that the sync runs at most once per hour.
   */
  public function testRunInterval(): void {
    $this->container->get(StateInterface::class)
      ->set(MobileNoteCronHooks::STATE_LAST_RUN, self::CURRENT_TIME - 60);

    $dataService = $this->createMock(MobileNoteDataService::class);
    $dataService->expects($this->never())->method('getMobileNoteData');

    $this->createSut(NULL, $dataService)->cron();
  }

  /**
   * Tests that nothing is tracked without the index.
   */
  public function testMissingIndex(): void {
    $dataService = $this->createMock(MobileNoteDataService::class);
    $dataService->method('getMobileNoteData')->willReturn([]);
    $dataService->expects($this->never())->method('getRetentionStart');

    $this->createSut(NULL, $dataService)->cron();
  }

  /**
   * Runs cron with the given API items, and moves the clock to the next run.
   *
   * @param list<\Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData> $items
   *   The items the API returns.
   */
  private function runCron(array $items): void {
    $data = [];
    foreach ($items as $item) {
      $data[$item->get('id')->getString()] = $item;
    }

    $dataService = $this->createMock(MobileNoteDataService::class);
    $dataService->method('getMobileNoteData')->willReturn($data);
    $dataService->method('getRetentionStart')->willReturn(self::RETENTION_START);

    $this->tracked = [];
    $this->createSut($this->createIndex(), $dataService)->cron();
    $this->currentTime += 3600;
  }

  /**
   * Asserts the item IDs tracked with the given method on the last run.
   *
   * @param list<string> $expected
   *   The expected item IDs.
   * @param string $method
   *   The tracking method.
   */
  private function assertTracked(array $expected, string $method): void {
    $actual = $this->tracked[$method] ?? [];
    sort($expected);
    sort($actual);

    $this->assertEquals($expected, $actual, $method);
  }

  /**
   * Creates an API item.
   */
  private function item(string $id, int $validTo, ?int $updatedAt = 100): MobileNoteData {
    $typedDataManager = $this->container->get('typed_data_manager');
    $item = $typedDataManager->create($typedDataManager->createDataDefinition('mobilenote_data'));
    assert($item instanceof MobileNoteData);
    $item->setValue([
      'id' => $id,
      'valid_from' => $validTo - 7 * 86400,
      'valid_to' => $validTo,
      'updated_at' => $updatedAt,
    ]);

    return $item;
  }

  /**
   * Creates an index that records the tracking calls.
   */
  private function createIndex(): IndexInterface {
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getRemainingItemsCount')->willReturnCallback(fn () => $this->remainingItems);

    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('addCondition')
      ->with('valid_to', self::RETENTION_START, '<')
      ->willReturnSelf();
    $query->method('range')->with(0, 100)->willReturnSelf();
    $query->method('execute')->willReturnCallback(function () use ($query) {
      $results = new ResultSet($query);
      foreach ($this->expiredIds as $id) {
        $item = $this->createMock(ItemInterface::class);
        $item->method('getId')->willReturn("mobilenote_data_source/$id");
        $results->addResultItem($item);
      }
      return $results;
    });

    $index = $this->createMock(IndexInterface::class);
    $index->method('query')->willReturn($query);
    $index->method('getTrackerInstanceIfAvailable')->willReturn($tracker);

    foreach (['trackItemsInserted', 'trackItemsUpdated', 'trackItemsDeleted'] as $method) {
      $index->method($method)->willReturnCallback(function (string $datasourceId, array $ids) use ($method): void {
        $this->assertEquals('mobilenote_data_source', $datasourceId);
        $this->tracked[$method] = [...$this->tracked[$method] ?? [], ...array_values($ids)];
      });
    }

    return $index;
  }

  /**
   * Creates the MobileNoteCronHooks instance with mocked dependencies.
   */
  private function createSut(?IndexInterface $index, MobileNoteDataService $dataService): MobileNoteCronHooks {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('mobilenote_data')->willReturn($index);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('search_api_index')->willReturn($storage);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($this->currentTime);

    return new MobileNoteCronHooks(
      $this->container->get(StateInterface::class),
      $this->container->get(KeyValueFactoryInterface::class),
      $etm,
      $time,
      $this->createMock(LoggerInterface::class),
      $dataService,
    );
  }

  /**
   * Gets the key-value store.
   */
  private function getStore(): KeyValueStoreInterface {
    return $this->container->get(KeyValueFactoryInterface::class)
      ->get(MobileNoteCronHooks::KEY_VALUE_COLLECTION);
  }

  /**
   * Sets the known items.
   *
   * @param array<string, int|null> $items
   *   The known items.
   */
  private function setKnownItems(array $items): void {
    $this->getStore()->set(MobileNoteCronHooks::KNOWN_ITEMS, $items);
  }

  /**
   * Gets the known items.
   *
   * @return array<string, int|null>
   *   The known items.
   */
  private function getKnownItems(): array {
    return $this->getStore()->get(MobileNoteCronHooks::KNOWN_ITEMS);
  }

}
