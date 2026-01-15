<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_mobilenote_sync\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\helfi_kymp_mobilenote_sync\MobileNoteSyncService;

/**
 * Cron hook implementations.
 */
class CronHooks {

  use AutowireTrait;

  private const STATE_LAST_RUN = 'helfi_kymp_mobilenote_sync.last_run';

  // Limit running this to once per hour.
  private const RUN_INTERVAL = 3600;

  public function __construct(
    private readonly MobileNoteSyncService $syncService,
    private readonly StateInterface $state,
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
    $this->syncService->sync();
  }

}
