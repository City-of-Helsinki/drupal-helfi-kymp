<?php

declare(strict_types=1);

namespace Drupal\helfi_kymp_content\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Change KYMP site specific settings, e.g. set project search page.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected AliasManagerInterface $aliasManager;

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected PathValidatorInterface $pathValidator;

  /**
   * Constructs a SettingsForm object for helfi_kymp_content.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\path_alias\AliasManagerInterface $alias_manager
   *   The path alias manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AliasManagerInterface $alias_manager) {
    parent::__construct($config_factory);
    $this->aliasManager = $alias_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : self {
    return new self(
      $container->get('config.factory'),
      $container->get('path_alias.manager'),
    );
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
