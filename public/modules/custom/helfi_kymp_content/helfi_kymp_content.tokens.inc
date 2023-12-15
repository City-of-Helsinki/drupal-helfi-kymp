<?php

/**
 * @file
 * Contains token data for helfi admin tools.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Implements hook_token_info().
 */
function helfi_kymp_content_token_info() : array {

  $info['tokens']['node']['lead-in'] = [
    'name' => t('Lead in'),
    'description' => t('Lead in will try to use the hero paragraph description if it exists. If not, it will use the node lead in field.'),
  ];

  return $info;
}

/**
 * Implements hook_tokens().
 */
function helfi_kymp_content_tokens(
  $type,
  $tokens,
  array $data,
  array $options,
  BubbleableMetadata $bubbleable_metadata
) : array {
  $replacements = [];

  foreach ($tokens as $name => $original) {

    if ($name === 'lead-in' && !empty($data['node'])) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $data['node'];
      $lead_in_text = '';

      // Check if hero paragraph and hero paragraph description exists.
      if (
        $node->hasField('field_hero') &&
        !$node->get('field_hero')?->first()?->isEmpty()
      ) {
        // Get hero paragraph.
        $hero = $node->get('field_hero')
          ?->first()
          ?->get('entity')
          ?->getTarget()
          ?->getValue();

        if (
          $hero instanceof ParagraphInterface &&
          $hero->hasField('field_hero_desc') &&
          !$hero->get('field_hero_desc')->isEmpty()
        ) {
          // Use hero paragraph description as lead in text.
          $lead_in_text = $hero->get('field_hero_desc')->value;
        }
      }

      // Check if lead in field exists.
      if (
        $node->hasField('field_lead_in') &&
        !$node?->get('field_lead_in')?->isEmpty()
      ) {
        // Use lead in field as lead in text.
        $lead_in_text = $node->get('field_lead_in')->value;
      }

      // Add lead in text to replacements.
      $replacements[$original] = $lead_in_text;
    }
  }

  return $replacements;
}
