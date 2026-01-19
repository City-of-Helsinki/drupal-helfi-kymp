<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\helfi_kymp_content\MobileNoteDataService;
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
    private readonly MobileNoteDataService $dataService,
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

}
