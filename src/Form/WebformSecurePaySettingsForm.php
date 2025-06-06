<?php

namespace Drupal\webform_securepay\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Simplified SecurePay settings form.
 */
class WebformSecurePaySettingsForm extends ConfigFormBase {

  private const CURRENCY_OPTIONS = [
    'AUD' => 'Australian Dollar (AUD)',
    'USD' => 'US Dollar (USD)',
    'EUR' => 'Euro (EUR)',
    'GBP' => 'British Pound (GBP)',
    'NZD' => 'New Zealand Dollar (NZD)',
    'CAD' => 'Canadian Dollar (CAD)',
    'JPY' => 'Japanese Yen (JPY)',
    'SGD' => 'Singapore Dollar (SGD)',
  ];

  private const CARD_TYPE_OPTIONS = [
    'visa' => 'Visa',
    'mastercard' => 'Mastercard',
    'amex' => 'American Express',
    'diners' => 'Diners Club',
  ];

  public function __construct(
    private readonly ConfigurationService $configService,
    private readonly SecurePayApiServiceInterface $apiService,
  ) {
    parent::__construct(\Drupal::configFactory());
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.api')
    );
  }

  public function getFormId(): string {
    return 'webform_securepay_admin_settings';
  }

  protected function getEditableConfigNames(): array {
    return [ConfigurationService::CONFIG_NAME];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = false;

    $form['authentication'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Authentication Settings'),
      '#description' => $this->t('OAuth 2.0 credentials from your SecurePay merchant dashboard.'),
    ];

    $form['authentication'][ConfigurationService::CLIENT_ID] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $this->configService->get(ConfigurationService::CLIENT_ID),
      '#required' => true,
      '#maxlength' => 255,
    ];

    $form['authentication'][ConfigurationService::CLIENT_SECRET] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->configService->get(ConfigurationService::CLIENT_SECRET) 
        ? $this->t('Leave empty to keep current secret.')
        : $this->t('Enter your client secret.'),
      '#maxlength' => 255,
    ];

    $form['authentication'][ConfigurationService::MERCHANT_CODE] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Code'),
      '#default_value' => $this->configService->get(ConfigurationService::MERCHANT_CODE),
      '#required' => true,
      '#maxlength' => 255,
    ];

    $form['authentication'][ConfigurationService::ENVIRONMENT] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#options' => [
        'sandbox' => $this->t('Sandbox (Testing)'),
        'live' => $this->t('Live (Production)'),
      ],
      '#default_value' => $this->configService->get(ConfigurationService::ENVIRONMENT),
      '#required' => true,
    ];

    $form['payment'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
    ];

    $form['payment'][ConfigurationService::CURRENCY] = [
      '#type' => 'select',
      '#title' => $this->t('Default Currency'),
      '#options' => self::CURRENCY_OPTIONS,
      '#default_value' => $this->configService->get(ConfigurationService::CURRENCY),
      '#required' => true,
    ];

    $form['payment'][ConfigurationService::ORDER_ID_PREFIX] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order ID Prefix'),
      '#default_value' => $this->configService->get(ConfigurationService::ORDER_ID_PREFIX),
      '#maxlength' => 10,
    ];

    $form['payment'][ConfigurationService::ALLOWED_CARD_TYPES] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed Card Types'),
      '#options' => self::CARD_TYPE_OPTIONS,
      '#default_value' => $this->configService->get(ConfigurationService::ALLOWED_CARD_TYPES),
      '#required' => true,
    ];

    $form['features'] = [
      '#type' => 'details',
      '#title' => $this->t('Feature Settings'),
      '#open' => false,
    ];

    $form['features'][ConfigurationService::DCC_ENABLED] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Dynamic Currency Conversion'),
      '#default_value' => $this->configService->get(ConfigurationService::DCC_ENABLED),
    ];

    $form['features'][ConfigurationService::THREE_DS_ENABLED] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable 3D Secure 2'),
      '#default_value' => $this->configService->get(ConfigurationService::THREE_DS_ENABLED),
    ];

    $form['features'][ConfigurationService::FRAUD_GUARD_ENABLED] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable FraudGuard'),
      '#default_value' => $this->configService->get(ConfigurationService::FRAUD_GUARD_ENABLED),
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => false,
    ];

    $form['advanced'][ConfigurationService::TIMEOUT] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout (seconds)'),
      '#default_value' => $this->configService->get(ConfigurationService::TIMEOUT),
      '#min' => 5,
      '#max' => 300,
    ];

    $form['advanced'][ConfigurationService::LOG_TRANSACTIONS] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log Transactions'),
      '#default_value' => $this->configService->get(ConfigurationService::LOG_TRANSACTIONS),
    ];

    $form['advanced'][ConfigurationService::DEBUG_MODE] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug Mode'),
      '#description' => $this->t('<strong>Do not enable in production!</strong>'),
      '#default_value' => $this->configService->get(ConfigurationService::DEBUG_MODE),
    ];

    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => false,
    ];

    $form['test']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test SecurePay Connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::testConnectionAjax',
        'wrapper' => 'test-connection-result',
      ],
    ];

    $form['test']['test_result'] = [
      '#type' => 'markup',
      '#prefix' => '<div id="test-connection-result">',
      '#suffix' => '</div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate card types
    $cardTypes = array_filter($form_state->getValue(ConfigurationService::ALLOWED_CARD_TYPES) ?: []);
    if (empty($cardTypes)) {
      $form_state->setErrorByName(ConfigurationService::ALLOWED_CARD_TYPES, 
        $this->t('At least one card type must be selected.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(ConfigurationService::CONFIG_NAME);
    
    // Save all configuration values
    $configKeys = [
      ConfigurationService::CLIENT_ID,
      ConfigurationService::MERCHANT_CODE,
      ConfigurationService::ENVIRONMENT,
      ConfigurationService::CURRENCY,
      ConfigurationService::ORDER_ID_PREFIX,
      ConfigurationService::DCC_ENABLED,
      ConfigurationService::THREE_DS_ENABLED,
      ConfigurationService::FRAUD_GUARD_ENABLED,
      ConfigurationService::TIMEOUT,
      ConfigurationService::LOG_TRANSACTIONS,
      ConfigurationService::DEBUG_MODE,
    ];

    foreach ($configKeys as $key) {
      $config->set($key, $form_state->getValue($key));
    }

    // Handle card types specially (filter empty values)
    $cardTypes = array_values(array_filter($form_state->getValue(ConfigurationService::ALLOWED_CARD_TYPES)));
    $config->set(ConfigurationService::ALLOWED_CARD_TYPES, $cardTypes);

    // Only update client secret if provided
    $clientSecret = $form_state->getValue(ConfigurationService::CLIENT_SECRET);
    if (!empty($clientSecret)) {
      $config->set(ConfigurationService::CLIENT_SECRET, $clientSecret);
    }
    
    $config->save();
    
    parent::submitForm($form, $form_state);
  }

  public function testConnection(array &$form, FormStateInterface $form_state): void {
    // Handled by AJAX callback
  }

  public function testConnectionAjax(array &$form, FormStateInterface $form_state): array {
    try {
      $success = $this->apiService->testConnection();
      $message = $success 
        ? $this->t('✅ Connection successful!')
        : $this->t('❌ Connection failed.');
      $class = $success ? 'messages--status' : 'messages--error';
    }
    catch (\Exception $e) {
      $message = $this->t('❌ Connection failed: @error', ['@error' => $e->getMessage()]);
      $class = 'messages--error';
    }

    return [
      '#type' => 'markup',
      '#markup' => '<div class="messages ' . $class . '">' . $message . '</div>',
      '#prefix' => '<div id="test-connection-result">',
      '#suffix' => '</div>',
    ];
  }
}