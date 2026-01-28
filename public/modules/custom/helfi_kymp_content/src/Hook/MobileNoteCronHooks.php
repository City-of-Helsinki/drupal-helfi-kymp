<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\helfi_kymp_content\MobileNoteDataService;

/**
 * Cron hook implementations for MobileNote.
 */
class MobileNoteCronHooks {

  private const STATE_LAST_RUN = 'helfi_kymp_content.mobilenote_last_run';

  // Limit running this to once per hour.
  private const RUN_INTERVAL = 3600;

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

    // Fetch data once to avoid redundant API calls/parsing.
    $data = $this->dataService->getMobileNoteData(FALSE);

    if (empty($data)) {
      $this->logger->info('MobileNote cron: No data to process.');
      return;
    }

    $this->trackNewItems($data);
    $this->trackExpiredItems($data);
  }

  /**
   * Track new items for indexing.
   *
   * @param \Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData[] $data
   *   The fetched data.
   */
  protected function trackNewItems(array $data): void {
    try {
      /** @var \Drupal\search_api\IndexInterface $index */
      $index = $this->entityTypeManager->getStorage('search_api_index')->load('mobilenote_data');
      if (!$index) {
        $this->logger->warning('MobileNote cron: Index not found.');
        return;
      }

      $source = $index->getDatasource('mobilenote_data_source');
      $cutoffTimestamp = $this->getCutoffTimestamp();
      $idsToIndex = [];

      foreach ($data as $id => $item) {
        $validTo = $item->get('valid_to')->getValue();
        // Index if not expired (valid_to is null or valid_to >= cutoff).
        if (!$validTo || $validTo >= $cutoffTimestamp) {
          $idsToIndex[] = $id;
        }
      }

      if ($idsToIndex) {
        $index->trackItemsInserted($source->getPluginId(), $idsToIndex);
        $this->logger->info('MobileNote cron: Tracked @count items for indexing.', [
          '@count' => count($idsToIndex),
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('MobileNote tracking failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Track expired items for deletion.
   *
   * Items are removed when (valid_to + sync_removal_offset) < today.
   *
   * @param \Drupal\helfi_kymp_content\Plugin\DataType\MobileNoteData[] $data
   *   The fetched data.
   */
  protected function trackExpiredItems(array $data): void {
    try {
      /** @var \Drupal\search_api\IndexInterface $index */
      $index = $this->entityTypeManager->getStorage('search_api_index')->load('mobilenote_data');
      if (!$index) {
        return;
      }

      $cutoffTimestamp = $this->getCutoffTimestamp();
      $source = $index->getDatasource('mobilenote_data_source');

      // Check each item and collect expired IDs.
      $expiredIds = [];
      foreach ($data as $id => $item) {
        $validTo = $item->get('valid_to')->getValue();
        if ($validTo && $validTo < $cutoffTimestamp) {
          $expiredIds[] = $id;
        }
      }

      if (!empty($expiredIds)) {
        $index->trackItemsDeleted($source->getPluginId(), $expiredIds);
        $this->logger->info('MobileNote cleanup: Marked @count expired items for deletion.', [
          '@count' => count($expiredIds),
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('MobileNote cleanup failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Get the cutoff timestamp for expiration.
   *
   * @return int
   *   The timestamp before which items are considered expired.
   */
  private function getCutoffTimestamp(): int {
    $settings = Settings::get('helfi_kymp_mobilenote', []);
    $removalOffset = $settings['sync_removal_offset'] ?? '+30 days';
    $invertedOffset = str_starts_with($removalOffset, '+')
      ? '-' . substr($removalOffset, 1)
      : '+' . substr($removalOffset, 1);

    return (new \DateTime())->setTimestamp($this->time->getRequestTime())->modify($invertedOffset)->getTimestamp();
  }

}
