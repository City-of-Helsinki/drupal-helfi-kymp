<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\EventSubscriber;

use Drupal\search_api\Event\ReindexScheduledEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribe to search api events.
 */
class SearchApiSubscriber implements EventSubscriberInterface {

  /**
   * The constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(private LoggerInterface $logger) {
  }

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::REINDEX_SCHEDULED => ['reindex'],
    ];
  }

  /**
   * Tell tracker which IDs to index on next indexing.
   *
   * @param \Drupal\search_api\Event\ReindexScheduledEvent $event
   *   The event.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function reindex(ReindexScheduledEvent $event): void {
    $index = $event->getIndex();

    // Handle street_data index.
    if ($index->id() == 'street_data') {
      $this->trackDatasourceItems($index, 'helfi_street_data_source');
    }


  }

  /**
   * Track items for a datasource.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   * @param string $datasourceId
   *   The datasource ID.
   */
  protected function trackDatasourceItems($index, string $datasourceId): void {
    try {
      $source = $index->getDatasource($datasourceId);
      $ids = $source->getItemIds();
      if (!$ids) {
        return;
      }
      $index->trackItemsInserted($source->getPluginId(), $ids);
    }
    catch (\Exception $e) {
      $this->logger->error('Unable to fetch data while running reindex event for @datasource: @message', [
        '@datasource' => $datasourceId,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
