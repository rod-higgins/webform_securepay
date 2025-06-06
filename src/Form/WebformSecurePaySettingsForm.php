<?php

namespace Drupal\webform_securepay\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\SecurePayApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Configuration form for SecurePay settings.
 */
class WebformSecurePaySettingsForm extends ConfigFormBase {

  // Form constants
  private const MIN_CLIENT_ID_LENGTH = 10;
  private const MIN_CLIENT_SECRET_LENGTH = 10;
  private const MIN_MERCHANT_CODE_LENGTH = 3;
  private const MAX_FIELD_LENGTH = 255;

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
    $form['#tree'] = TRUE;

    // Add help text
    $form['help'] = [
      '#type' => 'details',
      '#title' => $this->t('Setup Instructions'),
      '#open' => !$this->config->isConfigured(),
      '#weight' => -10,
    ];

    $form['help']['instructions'] = [
      '#markup' => $this->t('<p>To configure SecurePay:</p>
        <ol>
          <li>Log into your SecurePay merchant dashboard</li>
          <li>Navigate to API settings</li>
          <li>Create OAuth 2.0 credentials</li>
          <li>Copy the Client ID, Client Secret, and Merchant Code below</li>
          <li>Test the connection before going live</li>
        </ol>'),
    ];

    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('SecurePay Credentials'),
      '#description' => $this->t('OAuth 2.0 credentials from your SecurePay merchant dashboard.'),
      '#collapsible' => FALSE,
    ];

    $form['credentials']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $this->config->get('client_id'),
      '#required' => TRUE,
      '#description' => $this->t('Your SecurePay OAuth Client ID.'),
      '#maxlength' => self::MAX_FIELD_LENGTH,
      '#size' => 60,
    ];

    $form['credentials']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->config->get('client_secret') 
        ? $this->t('Leave empty to keep current secret.')
        : $this->t('Enter your OAuth client secret.'),
      '#maxlength' => self::MAX_FIELD_LENGTH,
      '#size' => 60,
    ];

    $form['credentials']['merchant_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Code'),
      '#default_value' => $this->config->get('merchant_code'),
      '#required' => TRUE,
      '#description' => $this->t('Your SecurePay merchant code.'),
      '#maxlength' => self::MAX_FIELD_LENGTH,
      '#size' => 30,
    ];

    $form['credentials']['environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#options' => ConfigurationService::getEnvironmentOptions(),
      '#default_value' => $this->config->get('environment') ?: ConfigurationService::ENVIRONMENT_SANDBOX,
      '#required' => TRUE,
      '#description' => $this->t('Use Sandbox for testing and Live for production. <strong>Warning:</strong> Live environment requires HTTPS.'),
    ];

    $form['payment'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
    ];

    $form['payment']['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Currency'),
      '#options' => ConfigurationService::getCurrencyOptions(),
      '#default_value' => $this->config->get('currency') ?: ConfigurationService::CURRENCY_AUD,
      '#required' => TRUE,
      '#description' => $this->t('Default currency for payments. This can be overridden per form element.'),
    ];

    $form['features'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Features'),
      '#open' => FALSE,
      '#description' => $this->t('These features require additional configuration with SecurePay.'),
    ];

    $form['features']['dcc_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Dynamic Currency Conversion (DCC)'),
      '#default_value' => $this->config->get('dcc_enabled'),
      '#description' => $this->t('Allow customers to pay in their card currency. Requires DCC setup with SecurePay.'),
    ];

    $form['features']['three_ds_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable 3D Secure 2'),
      '#default_value' => $this->config->get('three_ds_enabled'),
      '#description' => $this->t('Enhanced authentication for fraud protection. Requires 3DS2 setup with SecurePay.'),
    ];

    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection Test'),
      '#open' => FALSE,
    ];

    $form['test']['description'] = [
      '#markup' => $this->t('<p>Test your connection to SecurePay before saving. This will verify your credentials without processing any payments.</p>'),
    ];

    $form['test']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [['credentials']],
      '#attributes' => [
        'class' => ['button--secondary'],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $values = $form_state->getValues();

    // Validate Client ID
    $clientId = trim($values['credentials']['client_id']);
    if (strlen($clientId) < self::MIN_CLIENT_ID_LENGTH) {
      $form_state->setErrorByName('credentials][client_id', $this->t('Client ID must be at least @length characters long.', [
        '@length' => self::MIN_CLIENT_ID_LENGTH,
      ]));
    }
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $clientId)) {
      $form_state->setErrorByName('credentials][client_id', $this->t('Client ID contains invalid characters.'));
    }

    // Validate Client Secret (if provided)
    $clientSecret = trim($values['credentials']['client_secret']);
    if (!empty($clientSecret)) {
      if (strlen($clientSecret) < self::MIN_CLIENT_SECRET_LENGTH) {
        $form_state->setErrorByName('credentials][client_secret', $this->t('Client Secret must be at least @length characters long.', [
          '@length' => self::MIN_CLIENT_SECRET_LENGTH,
        ]));
      }
    } elseif (empty($this->config->get('client_secret'))) {
      $form_state->setErrorByName('credentials][client_secret', $this->t('Client Secret is required.'));
    }

    // Validate Merchant Code
    $merchantCode = trim($values['credentials']['merchant_code']);
    if (strlen($merchantCode) < self::MIN_MERCHANT_CODE_LENGTH) {
      $form_state->setErrorByName('credentials][merchant_code', $this->t('Merchant Code must be at least @length characters long.', [
        '@length' => self::MIN_MERCHANT_CODE_LENGTH,
      ]));
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $merchantCode)) {
      $form_state->setErrorByName('credentials][merchant_code', $this->t('Merchant Code contains invalid characters.'));
    }

    // Validate environment
    $environment = $values['credentials']['environment'];
    if (!$this->config->isValidEnvironment($environment)) {
      $form_state->setErrorByName('credentials][environment', $this->t('Invalid environment selected.'));
    }

    // Check HTTPS requirement for live environment
    if ($environment === ConfigurationService::ENVIRONMENT_LIVE) {
      $request = \Drupal::request();
      if (!$request->isSecure()) {
        $form_state->setErrorByName('credentials][environment', $this->t('HTTPS is required when using the live environment.'));
      }
    }

    // Validate currency
    $currency = $values['payment']['currency'];
    if (!$this->config->isValidCurrency($currency)) {
      $form_state->setErrorByName('payment][currency', $this->t('Invalid currency selected.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $config = $this->config(ConfigurationService::CONFIG_NAME);
    
    // Save credentials
    $config->set('client_id', trim($values['credentials']['client_id']))
           ->set('merchant_code', trim($values['credentials']['merchant_code']))
           ->set('environment', $values['credentials']['environment']);

    // Only update client secret if provided
    $clientSecret = trim($values['credentials']['client_secret']);
    if (!empty($clientSecret)) {
      $config->set('client_secret', $clientSecret);
    }

    // Save payment settings
    $config->set('currency', $values['payment']['currency']);

    // Save feature settings
    $config->set('dcc_enabled', (bool) $values['features']['dcc_enabled'])
           ->set('three_ds_enabled', (bool) $values['features']['three_ds_enabled']);

    $config->save();
    
    $this->messenger()->addStatus($this->t('SecurePay configuration has been saved.'));
    
    // Clear any cached connection test results
    \Drupal::state()->delete('webform_securepay.last_connection_test');
    
    parent::submitForm($form, $form_state);
  }

  /**
   * Test connection submit handler.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    
    // Temporarily save form values for testing
    $currentConfig = [
      'client_id' => $this->config->get('client_id'),
      'client_secret' => $this->config->get('client_secret'),
      'environment' => $this->config->get('environment'),
    ];

    try {
      // Set test values
      $testConfig = $this->config(ConfigurationService::CONFIG_NAME);
      $testConfig->set('client_id', trim($values['credentials']['client_id']))
                 ->set('environment', $values['credentials']['environment']);

      $clientSecret = trim($values['credentials']['client_secret']);
      if (!empty($clientSecret)) {
        $testConfig->set('client_secret', $clientSecret);
      } elseif (empty($this->config->get('client_secret'))) {
        $this->messenger()->addError($this->t('Client Secret is required for connection testing.'));
        return;
      }
      
      $testConfig->save();

      // Test connection
      $success = $this->apiService->testConnection();

      if ($success) {
        $this->messenger()->addStatus($this->t('✓ Connection successful! Your credentials are valid.'));
        \Drupal::state()->set('webform_securepay.last_connection_test', [
          'success' => TRUE,
          'timestamp' => time(),
          'environment' => $values['credentials']['environment'],
        ]);
      } else {
        $this->messenger()->addError($this->t('✗ Connection failed. Please check your credentials and try again.'));
        \Drupal::state()->set('webform_securepay.last_connection_test', [
          'success' => FALSE,
          'timestamp' => time(),
          'environment' => $values['credentials']['environment'],
        ]);
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Connection test failed: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
    finally {
      // Restore original config if we're not saving
      if (!$form_state->isSubmitted() || $form_state->hasAnyErrors()) {
        $restoreConfig = $this->config(ConfigurationService::CONFIG_NAME);
        foreach ($currentConfig as $key => $value) {
          if ($value !== null) {
            $restoreConfig->set($key, $value);
          }
        }
        $restoreConfig->save();
      }
    }
  }
}