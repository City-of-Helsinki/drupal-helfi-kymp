<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\helfi_kymp_content\Hook\MobileNoteCronHooks;
use Drupal\helfi_kymp_content\MobileNoteDataService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Entity\Index;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests MobileNoteCronHooks.
 *
 * @group helfi_kymp_content
 */
class MobileNoteCronHooksTest extends KernelTestBase {

  use ProphecyTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
    'search_api',
    'system',
    'user',
  ];

  /**
   * Tests cron indexing logic.
   */
  public function testCronIndexing(): void {
    // Use current time for consistent test behavior.
    $currentTime = time();

    // 1. Mock Datasource items.
    // Item 1: Active (no valid_to).
    $validTo1 = $this->prophesize(TypedDataInterface::class);
    $validTo1->getValue()->willReturn(NULL);

    $item1 = $this->prophesize(ComplexDataInterface::class);
    $item1->get('valid_to')->willReturn($validTo1->reveal());

    // Item 2: Expired recently (10 days ago). SHOULD BE INDEXED.
    $recentExpired = $currentTime - (10 * 24 * 60 * 60);
    $validTo2 = $this->prophesize(TypedDataInterface::class);
    $validTo2->getValue()->willReturn($recentExpired);

    $item2 = $this->prophesize(ComplexDataInterface::class);
    $item2->get('valid_to')->willReturn($validTo2->reveal());

    // Item 3: Expired long ago (40 days ago). SHOULD BE DELETED / SKIPPED.
    $oldExpired = $currentTime - (40 * 24 * 60 * 60);
    $validTo3 = $this->prophesize(TypedDataInterface::class);
    $validTo3->getValue()->willReturn($oldExpired);

    $item3 = $this->prophesize(ComplexDataInterface::class);
    $item3->get('valid_to')->willReturn($validTo3->reveal());

    $items = [
      'item1' => $item1->reveal(),
      'item2' => $item2->reveal(),
      'item3' => $item3->reveal(),
    ];

    // 2. Mock Datasource plugin.
    $datasource = $this->prophesize(DatasourceInterface::class);
    $datasource->loadMultiple([])->willReturn($items);
    $datasource->getPluginId()->willReturn('mobilenote_data_source');

    // 3. Mock Index entity.
    $index = $this->prophesize(Index::class);
    $index->getDatasource('mobilenote_data_source')->willReturn($datasource->reveal());

    // Test Reindex: Should track item1 and item2 only.
    $index->trackItemsInserted('mobilenote_data_source', ['item1', 'item2'])
      ->shouldBeCalled();
    $index->trackItemsInserted('mobilenote_data_source', Argument::containing('item3'))
      ->shouldNotBeCalled();

    // Test Cleanup: Should delete item3 only.
    $index->trackItemsDeleted('mobilenote_data_source', ['item3'])
      ->shouldBeCalled();

    // 4. Mock EntityTypeManager and Storage.
    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->load('mobilenote_data')->willReturn($index->reveal());

    $etm = $this->prophesize(EntityTypeManagerInterface::class);
    $etm->getStorage('search_api_index')->willReturn($storage->reveal());

    // 5. Mock State and Logger.
    $state = $this->prophesize(StateInterface::class);
    $state->get('helfi_kymp_content.mobilenote_last_run', 0)->willReturn(0);
    $state->set('helfi_kymp_content.mobilenote_last_run', Argument::any())->shouldBeCalled();

    $time = $this->prophesize(TimeInterface::class);
    $time->getRequestTime()->willReturn($currentTime);

    $dataService = $this->prophesize(MobileNoteDataService::class);
    $dataService->getMobileNoteData()->willReturn($items);

    $logger = $this->prophesize(LoggerInterface::class);
    $logger->info(Argument::type('string'), Argument::any())->shouldBeCalled();

    // 6. Instantiate Hooks class directly (unit-style testing within kernel).
    $hooks = new MobileNoteCronHooks(
      $state->reveal(),
      $etm->reveal(),
      $time->reveal(),
      $logger->reveal(),
      $dataService->reveal()
    );

    // Run cron.
    $hooks->cron();
  }

}
