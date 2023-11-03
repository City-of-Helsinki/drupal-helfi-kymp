<?php

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
   * @param LoggerInterface $logger
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
   * Reindex event.
   *
   * @param Drupal\search_api\Event\ReindexScheduledEvent $event
   *   The event.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function reindex(ReindexScheduledEvent $event): void {
    $index = $event->getIndex();
    if ($index->id() == 'street_data') {
      $source = $index->getDatasource('external_source');
      try {
        $data = $source->loadMultiple([]);
        if (!$data) {
          return;
        }
      }
      catch (\Exception $e) {
        $this->logger('unable to fetch data while running reindex event');
      }

      $index->trackItemsInserted($source->getPluginId(), array_keys($data));
    }
  }

}
