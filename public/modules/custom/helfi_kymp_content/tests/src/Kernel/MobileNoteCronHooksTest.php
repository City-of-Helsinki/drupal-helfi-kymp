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
   * Tests tracking new and updated items, and ended items after them.
   */
  public function testSync(): void {
    $this->setKnownItems(['updated' => 100, 'no.update.time' => NULL]);

    $items = [
      $this->item('updated', self::ACTIVE, 200),
      $this->item('no.update.time', self::ACTIVE, NULL),
      // Shown until a day after valid_to, so this is still active.
      $this->item('new', self::CURRENT_TIME - 86400),
      $this->item('ended', self::ENDED),
    ];

    $this->runCron($items);

    $this->assertTracked(['updated'], 'trackItemsUpdated');
    $this->assertTracked(['new'], 'trackItemsInserted');
    $this->assertEquals(['updated' => 200, 'no.update.time' => NULL, 'new' => 100], $this->getKnownItems());

    // The ended item waits until nothing is waiting to be indexed.
    $this->remainingItems = 1;
    $this->runCron($items);
    $this->assertEquals([], $this->tracked);

    $this->remainingItems = 0;
    $this->runCron($items);
    $this->assertEquals(['trackItemsInserted' => ['ended']], $this->tracked);
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
  }

  /**
   * Tests that expired items are deleted even if the API request fails.
   */
  public function testExpiredItems(): void {
    $this->setKnownItems(['known' => 100]);
    $this->expiredIds = ['expired.1', 'expired.2'];

    $this->runCron([]);

    $this->assertEquals(['trackItemsDeleted' => ['expired.1', 'expired.2']], $this->tracked);
    $this->assertEquals(['known' => 100], $this->getKnownItems());
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

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('mobilenote_data')->willReturn($this->createIndex());

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('search_api_index')->willReturn($storage);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($this->currentTime);

    $this->tracked = [];
    $hooks = new MobileNoteCronHooks(
      $this->container->get(StateInterface::class),
      $this->container->get(KeyValueFactoryInterface::class),
      $etm,
      $time,
      $this->createMock(LoggerInterface::class),
      $dataService,
    );
    $hooks->cron();
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
    $query->method('range')->willReturnSelf();
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
        $this->tracked[$method] = [...$this->tracked[$method] ?? [], ...array_values($ids)];
      });
    }

    return $index;
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
