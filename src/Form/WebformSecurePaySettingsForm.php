<?php

namespace Drupal\webform_securepay\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
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
    ConfigFactoryInterface $config_factory,
    private readonly ConfigurationService $config,
    private readonly SecurePayApiService $apiService,
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
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
      '#description' => $this->t('Your SecurePay OAuth Client ID.'),
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
      '#description' => $this->t('Your SecurePay merchant code.'),
    ];

    $form['credentials']['environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#options' => ConfigurationService::getEnvironmentOptions(),
      '#default_value' => $this->config->get('environment') ?: 'sandbox',
      '#required' => TRUE,
      '#description' => $this->t('Use Sandbox for testing and Live for production.'),
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
      '#description' => $this->t('Default currency for payments.'),
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate environment setting
    $environment = $form_state->getValue('environment');
    if (!in_array($environment, ['sandbox', 'live'])) {
      $form_state->setErrorByName('environment', $this->t('Invalid environment selected.'));
    }

    // Validate currency
    $currency = $form_state->getValue('currency');
    $validCurrencies = array_keys(ConfigurationService::getCurrencyOptions());
    if (!in_array($currency, $validCurrencies)) {
      $form_state->setErrorByName('currency', $this->t('Invalid currency selected.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(ConfigurationService::CONFIG_NAME);
    
    $config->set('client_id', trim($form_state->getValue('client_id')))
           ->set('merchant_code', trim($form_state->getValue('merchant_code')))
           ->set('environment', $form_state->getValue('environment'))
           ->set('currency', $form_state->getValue('currency'))
           ->set('dcc_enabled', (bool) $form_state->getValue('dcc_enabled'))
           ->set('three_ds_enabled', (bool) $form_state->getValue('three_ds_enabled'));

    // Only update client secret if provided
    $clientSecret = trim($form_state->getValue('client_secret'));
    if (!empty($clientSecret)) {
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
    // Temporarily save form values for testing
    $currentConfig = [
      'client_id' => $this->config->get('client_id'),
      'client_secret' => $this->config->get('client_secret'),
      'environment' => $this->config->get('environment'),
    ];

    // Set test values
    $testConfig = $this->config(ConfigurationService::CONFIG_NAME);
    $testConfig->set('client_id', trim($form_state->getValue('client_id')))
               ->set('environment', $form_state->getValue('environment'));

    $clientSecret = trim($form_state->getValue('client_secret'));
    if (!empty($clientSecret)) {
      $testConfig->set('client_secret', $clientSecret);
    }
    $testConfig->save();

    // Test connection
    $success = $this->apiService->testConnection();

    // Restore original config if test values were temporary
    if (!$form_state->isSubmitted() || $form_state->hasAnyErrors()) {
      $restoreConfig = $this->config(ConfigurationService::CONFIG_NAME);
      foreach ($currentConfig as $key => $value) {
        if ($value !== null) {
          $restoreConfig->set($key, $value);
        }
      }
      $restoreConfig->save();
    }

    if ($success) {
      $this->messenger()->addStatus($this->t('Connection successful!'));
    } else {
      $this->messenger()->addError($this->t('Connection failed. Please check your credentials.'));
    }
  }
}