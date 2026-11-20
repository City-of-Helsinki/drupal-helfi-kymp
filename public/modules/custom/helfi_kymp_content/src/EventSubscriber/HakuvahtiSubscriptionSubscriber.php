<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\EventSubscriber;

use Drupal\helfi_hakuvahti\Event\SubscriptionAlterEvent;
use Drupal\helfi_kymp_content\Hakuvahti\SearchDescriptionBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sets the search description of vehicle removal subscriptions.
 */
class HakuvahtiSubscriptionSubscriber implements EventSubscriberInterface {

  private const string CONFIG_ID = 'vehicle_removal';

  public function __construct(
    private readonly SearchDescriptionBuilder $searchDescriptionBuilder,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SubscriptionAlterEvent::class => 'onSubscriptionAlter',
    ];
  }

  /**
   * Builds the search description from the elastic query.
   *
   * @throws \InvalidArgumentException
   *   When the query cannot be decoded or its values cannot be checked.
   */
  public function onSubscriptionAlter(SubscriptionAlterEvent $event): void {
    if ($event->getConfigId() !== self::CONFIG_ID) {
      return;
    }

    $request = $event->getHakuvahtiRequest();
    $event->setHakuvahtiRequest($request->withSearchDescription(
      $this->searchDescriptionBuilder->build($request->decodeElasticQuery()),
    ));
  }

}
