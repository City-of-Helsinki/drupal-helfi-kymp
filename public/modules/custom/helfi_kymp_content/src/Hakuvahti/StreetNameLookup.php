<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Hakuvahti;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Utility\Error;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\Utility;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks street names against the Paikkatieto street name index.
 */
class StreetNameLookup {

  private const string INDEX_ID = 'paikkatieto_street_names';
  private const array LANGCODES = ['fi', 'sv', 'en'];
  private const string NAME_PATTERN = '/^[^\p{C}]{1,100}$/uD';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.channel.helfi_kymp_content')]
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Returns the names that exist in the street name index.
   *
   * The index item ids are '{langcode}:{name}', and the match is exact.
   *
   * @param list<string> $names
   *   The street names.
   *
   * @return list<string>
   *   The known names, in input order.
   *
   * @throws \InvalidArgumentException
   *   When the index is unavailable, empty or cannot be queried.
   */
  public function filterKnown(array $names): array {
    $names = array_values(array_filter(
      $names,
      static fn (string $name): bool => preg_match(self::NAME_PATTERN, $name) === 1,
    ));
    if (!$names) {
      return [];
    }

    $ids = [];
    foreach ($names as $name) {
      foreach (self::LANGCODES as $langcode) {
        $ids[] = "$langcode:$name";
      }
    }

    try {
      $index = $this->entityTypeManager->getStorage('search_api_index')->load(self::INDEX_ID);
      if (!$index instanceof IndexInterface || !$index->status()) {
        throw new \InvalidArgumentException('Street name index is not available.');
      }
      // The Elasticsearch backend gives an empty result for a missing index.
      if ($index->getTrackerInstance()->getIndexedItemsCount() === 0) {
        throw new \InvalidArgumentException('Street name index is empty.');
      }

      $query = $index->query();
      $query->addCondition('id', $ids, 'IN');
      $query->range(0, \count($ids));
      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);

      $known = [];
      foreach ($query->execute()->getResultItems() as $item) {
        [, $rawId] = Utility::splitCombinedId($item->getId());
        $known[explode(':', $rawId, 2)[1] ?? ''] = TRUE;
      }
    }
    catch (SearchApiException | PluginException $e) {
      Error::logException($this->logger, $e);
      throw new \InvalidArgumentException('Street names could not be checked.', previous: $e);
    }

    return array_values(array_filter($names, static fn (string $name): bool => isset($known[$name])));
  }

}
