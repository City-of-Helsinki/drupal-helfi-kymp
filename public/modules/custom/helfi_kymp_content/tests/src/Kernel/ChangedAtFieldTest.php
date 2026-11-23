<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\helfi_platform_config\Hook\ChangedAtFieldHooks;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the 'changed_at' field for district and project nodes.
 */
#[Group('helfi_kymp_content')]
#[RunTestsInSeparateProcesses]
class ChangedAtFieldTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'config_rewrite',
    'diff',
    'field',
    'helfi_api_base',
    'helfi_kymp_content',
    'helfi_platform_config',
    'node',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'node']);

    NodeType::create(['type' => 'district', 'name' => 'District'])->save();
    NodeType::create(['type' => 'project', 'name' => 'Project'])->save();
  }

  /**
   * Tests that 'changed_at' is set when a node form is submitted.
   */
  #[DataProvider('bundleProvider')]
  public function testChangedAtIsSet(string $bundle): void {
    $node = Node::create(['type' => $bundle, 'title' => 'test']);
    $node->save();

    $this->assertTrue($node->hasField('changed_at'));
    $this->assertTrue($node->get('changed_at')->isEmpty());

    $formObject = $this->container->get('entity_type.manager')->getFormObject('node', 'default');
    $formObject->setEntity($node);
    $formState = new FormState();
    $formState->setFormObject($formObject);

    ChangedAtFieldHooks::updateChangedAt([], $formState);

    $this->assertSame(
      $this->container->get('datetime.time')->getRequestTime(),
      (int) $node->get('changed_at')->value,
    );
  }

  /**
   * Data provider for testChangedAtIsSet().
   *
   * @return array<string, array{string}>
   *   The bundles.
   */
  public static function bundleProvider(): array {
    return [
      'district' => ['district'],
      'project' => ['project'],
    ];
  }

}
