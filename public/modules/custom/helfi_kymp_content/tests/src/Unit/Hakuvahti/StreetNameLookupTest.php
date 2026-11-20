<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Unit\Hakuvahti;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\helfi_kymp_content\Hakuvahti\StreetNameLookup;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Tracker\TrackerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests StreetNameLookup with mocked services.
 */
#[CoversClass(StreetNameLookup::class)]
#[Group('helfi_kymp_content')]
final class StreetNameLookupTest extends UnitTestCase {

  /**
   * Tests that a failed search is logged and fails the lookup.
   */
  public function testSearchFails(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('execute')->willThrowException(new SearchApiException('Connection refused'));

    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getIndexedItemsCount')->willReturn(1);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('getTrackerInstance')->willReturn($tracker);
    $index->method('query')->willReturn($query);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('paikkatieto_street_names')->willReturn($index);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('log');

    $lookup = new StreetNameLookup($entityTypeManager, $logger);

    $this->expectException(\InvalidArgumentException::class);
    $lookup->filterKnown(['Mannerheimintie']);
  }

  /**
   * Tests that a missing search_api_index entity type fails the lookup.
   */
  public function testStorageMissing(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willThrowException(new PluginNotFoundException('search_api_index'));

    $lookup = new StreetNameLookup($entityTypeManager, $this->createMock(LoggerInterface::class));

    $this->expectException(\InvalidArgumentException::class);
    $lookup->filterKnown(['Mannerheimintie']);
  }

  /**
   * Tests that invalid names do not query the index.
   */
  public function testInvalidNamesOnly(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $lookup = new StreetNameLookup($entityTypeManager, $this->createMock(LoggerInterface::class));

    $this->assertSame([], $lookup->filterKnown(['', "Kaivokatu\n", "\xFF"]));
  }

}
