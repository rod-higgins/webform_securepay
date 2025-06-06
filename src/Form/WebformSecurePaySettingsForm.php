<?php

namespace Drupal\webform_securepay\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\SecurePayApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for SecurePay settings.
 */
class WebformSecurePaySettingsForm extends ConfigFormBase {

  public function __construct(
    private readonly ConfigurationService $config,
    private readonly SecurePayApiService $apiService,
  ) {
    parent::__construct(\Drupal::configFactory());
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'webform_securepay_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ConfigurationService::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('SecurePay Credentials'),
      '#description' => $this->t('OAuth 2.0 credentials from your SecurePay merchant dashboard.'),
    ];

    $form['credentials']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $this->config->get('client_id'),
      '#required' => TRUE,
    ];

    $form['credentials']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->config->get('client_secret') 
        ? $this->t('Leave empty to keep current secret.')
        : $this->t('Enter your client secret.'),
    ];

    $form['credentials']['merchant_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Code'),
      '#default_value' => $this->config->get('merchant_code'),
      '#required' => TRUE,
    ];

    $form['credentials']['environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#options' => ConfigurationService::getEnvironmentOptions(),
      '#default_value' => $this->config->get('environment') ?: 'sandbox',
      '#required' => TRUE,
    ];

    $form['payment'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
    ];

    $form['payment']['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Currency'),
      '#options' => ConfigurationService::getCurrencyOptions(),
      '#default_value' => $this->config->get('currency') ?: 'AUD',
      '#required' => TRUE,
    ];

    $form['features'] = [
      '#type' => 'details',
      '#title' => $this->t('Features'),
      '#open' => FALSE,
    ];

    $form['features']['dcc_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Dynamic Currency Conversion'),
      '#default_value' => $this->config->get('dcc_enabled'),
      '#description' => $this->t('Allow customers to pay in their card currency.'),
    ];

    $form['features']['three_ds_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable 3D Secure 2'),
      '#default_value' => $this->config->get('three_ds_enabled'),
      '#description' => $this->t('Enhanced authentication for fraud protection.'),
    ];

    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => FALSE,
    ];

    $form['test']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(ConfigurationService::CONFIG_NAME);
    
    $config->set('client_id', $form_state->getValue('client_id'))
           ->set('merchant_code', $form_state->getValue('merchant_code'))
           ->set('environment', $form_state->getValue('environment'))
           ->set('currency', $form_state->getValue('currency'))
           ->set('dcc_enabled', (bool) $form_state->getValue('dcc_enabled'))
           ->set('three_ds_enabled', (bool) $form_state->getValue('three_ds_enabled'));

    // Only update client secret if provided
    if ($clientSecret = $form_state->getValue('client_secret')) {
      $config->set('client_secret', $clientSecret);
    }
    
    $config->save();
    
    $this->messenger()->addStatus($this->t('Configuration saved.'));
    
    parent::submitForm($form, $form_state);
  }

  /**
   * Test connection submit handler.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    if ($this->apiService->testConnection()) {
      $this->messenger()->addStatus($this->t('Connection successful!'));
    } else {
      $this->messenger()->addError($this->t('Connection failed. Please check your credentials.'));
    }
  }
}