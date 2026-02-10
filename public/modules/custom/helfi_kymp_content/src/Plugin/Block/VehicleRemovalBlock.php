<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\helfi_hakuvahti\DrupalSettings;

/**
 * Block that displays the Hakuvahti signup form
 */
#[Block(
  id: 'kymp_vehicle_removal',
  admin_label: new TranslatableMarkup('Hakuvahti'),
)]
final class VehicleRemovalBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly DrupalSettings $drupalSettings,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritDoc}
   */
  public function build(): array {
    $build = [
      '#theme' => 'vehicle_removal',
      '#attached' => [
        'library' => [
          'helfi_kymp_content/vehicle-removal-search'
        ],
      ]
    ];

    // Apply hakuvahti settings.
    $this->drupalSettings->applyTo($build);

    return $build;
  }

}
