<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\search_api\Entity\Index;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cron hook implementations for MobileNote.
 */
class MobileNoteCronHooks {

  use AutowireTrait;

  private const STATE_LAST_RUN = 'helfi_kymp_content.mobilenote_last_run';

  // Limit running this to once per hour.
  private const RUN_INTERVAL = 3600;

  public function __construct(
    private readonly StateInterface $state,
    #[Autowire(service: 'logger.channel.helfi_kymp_content')]
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $lastRun = $this->state->get(self::STATE_LAST_RUN, 0);
    if (time() - $lastRun < self::RUN_INTERVAL) {
      return;
    }
    $this->state->set(self::STATE_LAST_RUN, time());
    $this->reindexMobileNoteData();
    $this->cleanupExpiredItems();
  }

  /**
   * Reindex MobileNote data.
   */
  protected function reindexMobileNoteData(): void {
    try {
      $index = Index::load('mobilenote_data');
      if (!$index) {
        $this->logger->warning('MobileNote cron: Index not found.');
        return;
      }

      $source = $index->getDatasource('mobilenote_data_source');
      $data = $source->loadMultiple([]);
      if (!$data) {
        $this->logger->info('MobileNote cron: No data to index.');
        return;
      }

      $ids = array_keys($data);
      $index->trackItemsInserted($source->getPluginId(), $ids);
      $this->logger->info('MobileNote cron: Tracked @count items for indexing.', [
        '@count' => count($ids),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('MobileNote cron failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Remove expired items from Elasticsearch.
   *
   * Items are removed when (valid_to + sync_removal_offset) < today.
   */
  protected function cleanupExpiredItems(): void {
    try {
      $index = Index::load('mobilenote_data');
      if (!$index) {
        return;
      }

      $settings = Settings::get('helfi_kymp_mobilenote', []);
      $removalOffset = $settings['sync_removal_offset'] ?? '+30 days';

      // '+30 days' means keep for 30 days after expiry,
      // so delete where valid_to < (today - 30 days).
      $invertedOffset = str_starts_with($removalOffset, '+')
        ? '-' . substr($removalOffset, 1)
        : '+' . substr($removalOffset, 1);

      $cutoffTimestamp = (new \DateTime())->modify($invertedOffset)->getTimestamp();

      // Get all current items from the datasource.
      $source = $index->getDatasource('mobilenote_data_source');
      $currentData = $source->loadMultiple([]);

      // Check each item and collect expired IDs.
      $expiredIds = [];
      foreach ($currentData as $id => $item) {
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

}
