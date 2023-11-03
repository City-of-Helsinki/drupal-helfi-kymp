<?php

namespace Drupal\helfi_kymp_content\EventSubscriber;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Event\GatheringPluginInfoEvent;
use Drupal\search_api\Event\ReindexScheduledEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribe to search api events.
 */
class SearchApiSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::REINDEX_SCHEDULED => ['reindex'],
      SearchApiEvents::GATHERING_PROCESSORS => ['disableProcessor'],
    ];
  }

  /**
   * Disable incompatible processor.
   *
   * @param GatheringPluginInfoEvent $event
   *   The event.
   */
  public function disableProcessor(GatheringPluginInfoEvent $event) {
    $processors = $event->getDefinitions();
    if (isset($processors['media_reference_to_object'])) {
      unset($processors['media_reference_to_object']);
    }
  }

  /**
   * Reindex event.
   *
   * @param ReindexScheduledEvent $event
   *   The event.
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
    catch (\Exception $e) {
      \Drupal::logger('helfi_kymp_content')->error(
        'unable to fetch data while running reindex event'
      );
    }

    $index->trackItemsInserted($source->getPluginId(), array_keys($data));
  }

}
