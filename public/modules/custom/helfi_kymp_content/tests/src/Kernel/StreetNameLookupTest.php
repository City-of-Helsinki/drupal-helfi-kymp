<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\helfi_kymp_content\Hakuvahti\StreetNameLookup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\helfi_kymp_content\Traits\StreetNameIndexTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests StreetNameLookup.
 */
#[CoversClass(StreetNameLookup::class)]
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class StreetNameLookupTest extends KernelTestBase {

  use StreetNameIndexTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_api_base',
    'helfi_kymp_content',
    'search_api',
    'search_api_db',
    'user',
    'diff',
  ];

  /**
   * Gets the lookup service.
   */
  private function getLookup(): StreetNameLookup {
    return $this->container->get(StreetNameLookup::class);
  }

  /**
   * Tests that only names in the index are returned.
   */
  public function testFilterKnown(): void {
    $this->createStreetNameIndex([
      'fi:Mannerheimintie',
      'sv:Mannerheimvägen',
      'en:Esplanade',
      'fi:Kauppatori: Etelä',
    ]);

    $this->assertSame(
      ['Esplanade', 'Mannerheimvägen', 'Kauppatori: Etelä', 'Mannerheimintie'],
      $this->getLookup()->filterKnown([
        'Esplanade',
        'mannerheimintie',
        'Mannerheimvägen',
        'Kaivokatu',
        "Mannerheimintie\n",
        "Mannerheim\u{202E}intie",
        'Kauppatori: Etelä',
        'Mannerheimintie',
        str_repeat('a', 101),
      ]),
    );
    $this->assertSame([], $this->getLookup()->filterKnown([]));
    $this->assertSame([], $this->getLookup()->filterKnown(["\x00"]));
  }

  /**
   * Tests an empty index.
   */
  public function testEmptyIndex(): void {
    $this->createStreetNameIndex([]);
    $this->expectException(\InvalidArgumentException::class);
    $this->getLookup()->filterKnown(['Mannerheimintie']);
  }

  /**
   * Tests an index with tracked items that are not indexed yet.
   */
  public function testNotIndexed(): void {
    $index = $this->createStreetNameIndex([]);
    $index->trackItemsInserted('paikkatieto_street_name_source', ['fi:Mannerheimintie']);

    $this->expectException(\InvalidArgumentException::class);
    $this->getLookup()->filterKnown(['Mannerheimintie']);
  }

  /**
   * Tests a missing index.
   */
  public function testMissingIndex(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->getLookup()->filterKnown(['Mannerheimintie']);
  }

  /**
   * Tests a disabled index.
   */
  public function testDisabledIndex(): void {
    $this->createStreetNameIndex(['fi:Mannerheimintie']);
    Index::load('paikkatieto_street_names')?->disable()->save();

    $this->expectException(\InvalidArgumentException::class);
    $this->getLookup()->filterKnown(['Mannerheimintie']);
  }

}
