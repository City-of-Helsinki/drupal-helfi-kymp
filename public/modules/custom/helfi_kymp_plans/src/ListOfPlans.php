<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_plans;

/**
 * Class for getting the list of plans from a RSS feed.
 */
class ListOfPlans {

  /**
   * The constructor.
   *
   * @param string $rss_feed_url
   *   RSS feed URL.
   */
  public function __construct(
    public string $rss_feed_url = 'https://ptp.hel.fi/rss/nahtavana_nyt/'
  ) {}

  /**
   * Returns the RSS feed URL.
   *
   * @return string
   *   RSS feed URL.
   */
  public function getFeedUrl(): string {
    return $this->rss_feed_url;
  }

  /**
   * Get the list of plans from the RSS feed.
   *
   * @return array
   *   List of plans in an array.
   */
  public function getPlans() : array {
    // Get the list of plans from the RSS feed.
    $rss = simplexml_load_file($this->getFeedUrl());

    $plans = [];

    foreach ($rss->channel->item as $item) {
      $plans[] = [
        'title' => (string) $item->title,
        'pub_date' => (date('j.n.Y', strtotime((string) $item->pubDate))),
        'link' => (string) $item->link,
      ];
    }

    return $plans;
  }

}
