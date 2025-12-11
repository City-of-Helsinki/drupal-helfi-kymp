<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManager;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Change KYMP site specific settings, e.g. set project search page.
 */
final class SettingsForm extends ConfigFormBase {

  use AutowireTrait;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManager $typedConfigManager,
    private AliasManagerInterface $aliasManager,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'helfi_kymp_content.settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() : array {
    return ['helfi_kymp_content.project_search'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $projectSearchConfig = $this->config('helfi_kymp_content.project_search');

    $form['project_search']['project_search_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path for project search page'),
      '#default_value' => $projectSearchConfig->get('project_search_path'),
      '#size' => 40,
      '#description' => $this->t('This path is used when redirecting users from project listing to the project search page.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->isValueEmpty('project_search_path')) {
      $form_state->setValueForElement($form['project_search']['project_search_path'], $this->aliasManager->getPathByAlias($form_state->getValue('project_search_path')));
    }
    if (($value = $form_state->getValue('project_search_path')) && $value[0] !== '/') {
      $form_state->setErrorByName('project_search_path',
        $this->t("The path '%path' has to start with a slash.", ['%path' => $form_state->getValue('project_search_path')])
      );
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('helfi_kymp_content.project_search')
      ->set('project_search_path', $form_state->getValue('project_search_path'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
