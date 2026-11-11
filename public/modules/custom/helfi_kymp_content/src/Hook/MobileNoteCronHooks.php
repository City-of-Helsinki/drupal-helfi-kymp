<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\search_api\Utility\Utility;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cron hook implementations for MobileNote.
 */
class MobileNoteCronHooks {

  public const string STATE_LAST_RUN = 'helfi_kymp_content.mobilenote_last_run';

  /**
   * The key-value collection for the known items.
   */
  public const string KEY_VALUE_COLLECTION = 'helfi_kymp_content.mobilenote';

  /**
   * The tracked items: updated_at values keyed by item ID.
   *
   * This is kept in key-value instead of state: with the retention period it
   * holds thousands of items, and state would load them on every request.
   */
  public const string KNOWN_ITEMS = 'known_items';

  /**
   * The state key where the known items were stored before.
   */
  private const string LEGACY_KNOWN_ITEMS = 'helfi_kymp_content.mobilenote_known_items';

  /**
   * The maximum number of ended items to start tracking per run.
   *
   * The API returns items that ended during the retention period, which the
   * index does not have after an upgrade. Indexing an item takes about a
   * second, because of the address and parking zone lookups, so tracking them
   * all at once would make new items wait hours to be indexed.
   */
  public const int MAX_BACKFILL_PER_RUN = 250;

  // Limit running this to once per hour.
  private const int RUN_INTERVAL = 3600;

  public function __construct(
    private readonly StateInterface $state,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.helfi_kymp_content')]
    private readonly LoggerInterface $logger,
    private readonly MobileNoteDataService $dataService,
  ) {
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $currentTime = $this->time->getRequestTime();
    $lastRun = $this->state->get(self::STATE_LAST_RUN, 0);

    if ($currentTime - $lastRun < self::RUN_INTERVAL) {
      return;
    }
    $this->state->set(self::STATE_LAST_RUN, $currentTime);

    $data = $this->dataService->getMobileNoteData();

    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load('mobilenote_data');

    if (!$index) {
      return;
    }

    // Query the index for items that ended before the retention period. The
    // API no longer returns them.
    $query = $index->query()
      ->addCondition('valid_to', $this->dataService->getRetentionStart(), '<')
      // The query should have a limit.
      ->range(0, 100);

    $expiredIds = [];

    foreach ($query->execute() as $result) {
      [, $id] = Utility::splitCombinedId($result->getId());

      if (!in_array($id, $expiredIds)) {
        $expiredIds[] = $id;
      }
    }

    if ($expiredIds) {
      $index->trackItemsDeleted('mobilenote_data_source', $expiredIds);
      $this->logger->info('MobileNote: Marked @count expired items for deletion.', [
        '@count' => count($expiredIds),
      ]);
    }

    // An empty response means that the request failed. Keep the known items,
    // so that they are not tracked as new on the next run.
    if (!$data) {
      $this->logger->warning('MobileNote: The API returned no items.');
      return;
    }

    $this->syncIndex($index, 'mobilenote_data_source', $data, $currentTime);
  }

  /**
   * Syncs the search index by tracking new and updated items.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search API index.
   * @param string $datasourceId
   *   The datasource plugin ID.
   * @param array<string, \Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData> $data
   *   All items from the API, keyed by ID.
   * @param int $currentTime
   *   The current time.
   */
  protected function syncIndex(IndexInterface $index, string $datasourceId, array $data, int $currentTime): void {
    $store = $this->keyValueFactory->get(self::KEY_VALUE_COLLECTION);
    $knownItems = $store->get(self::KNOWN_ITEMS);

    // On the first run after the upgrade, start from the items in state.
    $legacy = $knownItems === NULL;
    if ($legacy) {
      $knownItems = $this->state->get(self::LEGACY_KNOWN_ITEMS, []);
    }

    // Items are shown until a day after valid_to.
    $activeLimit = $currentTime - 86400;

    $currentItems = [];
    $newIds = [];
    $updatedIds = [];
    $endedIds = [];

    foreach ($data as $id => $item) {
      $updatedAt = $item->get('updated_at')->getValue();

      // The updated_at value can be NULL, so isset() would not work here.
      if (array_key_exists($id, $knownItems)) {
        $currentItems[$id] = $updatedAt;

        if ($knownItems[$id] !== $updatedAt) {
          $updatedIds[] = $id;
        }
      }
      elseif ($item->get('valid_to')->getValue() >= $activeLimit) {
        $currentItems[$id] = $updatedAt;
        $newIds[] = $id;
      }
      else {
        // Ended items that are not tracked yet stay out of the known items
        // until they are tracked below, on this or a later run.
        $endedIds[$id] = (int) $item->get('valid_to')->getValue();
      }
    }

    // Track ended items in chunks, latest first. Only do it when nothing is
    // waiting to be indexed and this run has no new or updated items, so
    // that they never wait behind a chunk.
    $backfillIds = [];
    $queueEmpty = $index->getTrackerInstanceIfAvailable()?->getRemainingItemsCount() === 0;

    if ($endedIds && $queueEmpty && !$newIds && !$updatedIds) {
      arsort($endedIds);
      $backfillIds = array_slice(array_keys($endedIds), 0, self::MAX_BACKFILL_PER_RUN);

      foreach ($backfillIds as $id) {
        $currentItems[$id] = $data[$id]->get('updated_at')->getValue();
      }
    }

    $store->set(self::KNOWN_ITEMS, $currentItems);

    if ($legacy) {
      $this->state->delete(self::LEGACY_KNOWN_ITEMS);
    }

    if ($newIds) {
      $index->trackItemsInserted($datasourceId, $newIds);
      $this->logger->info('MobileNote: Tracked @count new items for indexing.', [
        '@count' => count($newIds),
      ]);
    }

    if ($updatedIds) {
      $index->trackItemsUpdated($datasourceId, $updatedIds);
      $this->logger->info('MobileNote: Tracked @count updated items for re-indexing.', [
        '@count' => count($updatedIds),
      ]);
    }

    if ($backfillIds) {
      $index->trackItemsInserted($datasourceId, $backfillIds);
      $this->logger->info('MobileNote: Tracked @count of @total ended items for indexing.', [
        '@count' => count($backfillIds),
        '@total' => count($endedIds),
      ]);
    }
  }

}
