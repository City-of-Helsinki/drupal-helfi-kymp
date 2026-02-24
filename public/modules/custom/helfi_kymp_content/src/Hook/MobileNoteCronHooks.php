<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
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
  public const string STATE_KNOWN_ITEMS = 'helfi_kymp_content.mobilenote_known_items';

  // Limit running this to once per hour.
  private const int RUN_INTERVAL = 3600;

  public function __construct(
    private readonly StateInterface $state,
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

    $expiredIds = [];

    // Get expired items.
    foreach ($data as $id => $item) {
      $validTo = $item->get('valid_to')->getValue();

      if ($validTo && $validTo < $currentTime) {
        $expiredIds[] = $id;
      }
    }

    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load('mobilenote_data');

    if (!$index) {
      return;
    }

    // Query the index for expired items that the API may no longer return.
    // Items whose valid_from is older than the API's lookback window will
    // not appear in $data but may still exist in the index.
    $query = $index->query()
      // Elasticsearch expects milliseconds on date columns.
      ->addCondition('valid_to', $currentTime * 1000, '<')
      // The query should have a limit.
      ->range(0, 100);

    foreach ($query->execute() as $result) {
      [, $id] = Utility::splitCombinedId($result->getId());

      if (!in_array($id, $expiredIds)) {
        $expiredIds[] = $id;
      }
    }

    $activeIds = array_diff(array_keys($data), $expiredIds);

    $this->syncIndex($index, 'mobilenote_data_source', $data, $activeIds, $expiredIds);
  }

  /**
   * Syncs the search index by tracking new, updated, and expired items.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search API index.
   * @param string $datasourceId
   *   The datasource plugin ID.
   * @param array $data
   *   All items from the API, keyed by ID.
   * @param array $activeIds
   *   IDs of non-expired items to index.
   * @param array $expiredIds
   *   IDs of expired items to delete.
   */
  protected function syncIndex(IndexInterface $index, string $datasourceId, array $data, array $activeIds, array $expiredIds): void {
    $knownItems = $this->state->get(self::STATE_KNOWN_ITEMS, []);
    $currentItems = [];
    $newIds = [];
    $updatedIds = [];

    foreach ($activeIds as $id) {
      $updatedAt = $data[$id]->get('updated_at')->getValue();
      $currentItems[$id] = $updatedAt;

      if (!isset($knownItems[$id])) {
        $newIds[] = $id;
      }
      elseif ($knownItems[$id] !== $updatedAt) {
        $updatedIds[] = $id;
      }
    }

    $this->state->set(self::STATE_KNOWN_ITEMS, $currentItems);

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

    if ($expiredIds) {
      $index->trackItemsDeleted($datasourceId, $expiredIds);
      $this->logger->info('MobileNote: Marked @count expired items for deletion.', [
        '@count' => count($expiredIds),
      ]);
    }
  }

}
