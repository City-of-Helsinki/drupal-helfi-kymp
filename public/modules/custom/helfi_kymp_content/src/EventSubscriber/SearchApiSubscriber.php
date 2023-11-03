<?php

namespace Drupal\helfi_kymp_content\EventSubscriber;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Event\ReindexScheduledEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SearchApiSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::REINDEX_SCHEDULED => ['reindex']
    ];
  }

  /**
   * Reindex event.
   *
   * @param ReindexScheduledEvent $event
   *   the event.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function reindex(ReindexScheduledEvent $event): void {
    $index = Index::load('street_data');
    $source = $index->getDatasource('external_source');
    try {
      $data = $source->loadMultiple([]);
      if (!$data) {
        return;
      }
    }
    catch(\Exception $e) {
      // Logging goes here
    }

    $index->trackItemsInserted($source->getPluginId(), array_keys($data));
  }

}
