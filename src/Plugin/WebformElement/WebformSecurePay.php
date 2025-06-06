<?php

namespace Drupal\webform_securepay\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;
use Drupal\webform_securepay\Service\ConfigurationService;

/**
 * Provides a 'securepay' element.
 *
 * @WebformElement(
 *   id = "securepay",
 *   label = @Translation("SecurePay"),
 *   description = @Translation("Provides SecurePay payment processing with modern REST API."),
 *   category = @Translation("Payment"),
 * )
 */
class WebformSecurePay extends WebformElementBase implements ContainerFactoryPluginInterface {

  /**
   * The SecurePay API service.
   */
  protected SecurePayApiServiceInterface $securePayApi;

  /**
   * The configuration service.
   */
  protected ConfigurationService $configService;

  /**
   * Constructs a WebformSecurePay object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    SecurePayApiServiceInterface $securepay_api,
    ConfigurationService $config_service
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->securePayApi = $securepay_api;
    $this->configService = $config_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('webform_securepay.api'),
      $container->get('webform_securepay.configuration')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineDefaultProperties() {
    return [
      // Authentication settings (usually inherited from global config)
      'client_id' => '',
      'client_secret' => '',
      'merchant_code' => '',
      'environment' => '',
      
      // Payment settings
      'amount' => '',
      'currency' => '',
      'mode' => '',
      'order_id_prefix' => '',
      
      // Card settings
      'allowed_card_types' => [],
      'show_card_icons' => NULL,
      
      // UI settings
      'background_color' => '',
      'label_font_family' => '',
      'label_font_size' => '',
      'label_color' => '',
      'input_font_family' => '',
      'input_font_size' => '',
      'input_color' => '',
      
      // Feature flags
      'dcc_enabled' => NULL,
      'three_ds_enabled' => NULL,
      'fraud_guard_enabled' => NULL,
      'auto_focus' => NULL,
      'bin_check_enabled' => NULL,
      
      // Advanced settings
      'timeout' => NULL,
      'log_transactions' => NULL,
      'custom_callbacks' => '',
      
      // Standard properties
      'title' => '',
      'description' => '',
      'required' => FALSE,
    ] + parent::defineDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    $properties = parent::getDefaultProperties();
    
    // Merge with global configuration defaults
    $config_keys = [
      'client_id', 'client_secret', 'merchant_code', 'environment',
      'currency', 'mode' => 'default_mode', 'order_id_prefix',
      'allowed_card_types', 'show_card_icons',
      'background_color', 'label_font_family', 'label_font_size', 'label_color',
      'input_font_family', 'input_font_size', 'input_color',
      'dcc_enabled', 'three_ds_enabled', 'fraud_guard_enabled',
      'auto_focus', 'bin_check_enabled', 'timeout', 'log_transactions',
    ];

    foreach ($config_keys as $property => $config_key) {
      if (is_numeric($property)) {
        $property = $config_key;
      }
      
      if (empty($properties[$property])) {
        $properties[$property] = $this->configService->get($config_key);
      }
    }
    
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    // Authentication Settings
    $form['authentication'] = $this->buildAuthenticationSection($form_state);
    
    // Payment Settings
    $form['payment'] = $this->buildPaymentSection($form_state);
    
    // Card Settings
    $form['card'] = $this->buildCardSection($form_state);
    
    // UI Style Settings
    $form['style'] = $this->buildStyleSection($form_state);
    
    // Feature Settings
    $form['features'] = $this->buildFeaturesSection($form_state);
    
    // Advanced Settings
    $form['advanced'] = $this->buildAdvancedSection($form_state);
    
    // Test Connection
    $form['test'] = $this->buildTestSection();

    return $form;
  }

  /**
   * Build authentication section.
   */
  protected function buildAuthenticationSection(FormStateInterface $form_state): array {
    $auth_settings = $this->configService->getAuthSettings();

    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Authentication Settings'),
      '#description' => $this->t('Leave fields empty to use global defaults.'),
      'client_id' => [
        '#type' => 'textfield',
        '#title' => $this->t('Client ID'),
        '#description' => $this->t('Global default: @default', [
          '@default' => $auth_settings[ConfigurationService::CLIENT_ID] ?: $this->t('Not set'),
        ]),
        '#default_value' => $this->getElementProperty($form_state, 'client_id'),
        '#maxlength' => 255,
      ],
      'client_secret' => [
        '#type' => 'password',
        '#title' => $this->t('Client Secret'),
        '#description' => $this->t('Global default: @default', [
          '@default' => $auth_settings[ConfigurationService::CLIENT_SECRET] ? $this->t('Set') : $this->t('Not set'),
        ]),
        '#default_value' => $this->getElementProperty($form_state, 'client_secret'),
        '#maxlength' => 255,
      ],
      'merchant_code' => [
        '#type' => 'textfield',
        '#title' => $this->t('Merchant Code'),
        '#default_value' => $this->getElementProperty($form_state, 'merchant_code'),
        '#maxlength' => 255,
      ],
      'environment' => [
        '#type' => 'select',
        '#title' => $this->t('Environment'),
        '#options' => [
          'sandbox' => $this->t('Sandbox (Testing)'),
          'live' => $this->t('Live (Production)'),
        ],
        '#default_value' => $this->getElementProperty($form_state, 'environment'),
      ],
    ];
  }

  /**
   * Build payment section.
   */
  protected function buildPaymentSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
      'amount' => [
        '#type' => 'textfield',
        '#title' => $this->t('Amount'),
        '#description' => $this->t('Payment amount in cents or token like [webform_submission:values:amount_field].'),
        '#default_value' => $this->getElementProperty($form_state, 'amount'),
        '#required' => TRUE,
      ],
      'currency' => [
        '#type' => 'select',
        '#title' => $this->t('Currency'),
        '#options' => $this->getCurrencyOptions(),
        '#default_value' => $this->getElementProperty($form_state, 'currency'),
        '#required' => TRUE,
      ],
      'mode' => [
        '#type' => 'select',
        '#title' => $this->t('Payment Mode'),
        '#options' => [
          'checkout' => $this->t('Checkout (Standard)'),
          'dcc' => $this->t('DCC (Dynamic Currency Conversion)'),
        ],
        '#default_value' => $this->getElementProperty($form_state, 'mode'),
      ],
      'order_id_prefix' => [
        '#type' => 'textfield',
        '#title' => $this->t('Order ID Prefix'),
        '#default_value' => $this->getElementProperty($form_state, 'order_id_prefix'),
        '#maxlength' => 10,
      ],
    ];
  }

  /**
   * Build card section.
   */
  protected function buildCardSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Card Settings'),
      'allowed_card_types' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed Card Types'),
        '#options' => $this->getCardTypeOptions(),
        '#default_value' => $this->getElementProperty($form_state, 'allowed_card_types'),
      ],
      'show_card_icons' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show Card Icons'),
        '#default_value' => $this->getElementProperty($form_state, 'show_card_icons'),
      ],
    ];
  }

  /**
   * Build style section.
   */
  protected function buildStyleSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('UI Style Settings'),
      '#open' => FALSE,
      'background_color' => [
        '#type' => 'textfield',
        '#title' => $this->t('Background Color'),
        '#default_value' => $this->getElementProperty($form_state, 'background_color'),
      ],
      'label_settings' => [
        '#type' => 'fieldset',
        '#title' => $this->t('Label Settings'),
        'label_font_family' => [
          '#type' => 'textfield',
          '#title' => $this->t('Font Family'),
          '#default_value' => $this->getElementProperty($form_state, 'label_font_family'),
        ],
        'label_font_size' => [
          '#type' => 'textfield',
          '#title' => $this->t('Font Size'),
          '#default_value' => $this->getElementProperty($form_state, 'label_font_size'),
        ],
        'label_color' => [
          '#type' => 'textfield',
          '#title' => $this->t('Color'),
          '#default_value' => $this->getElementProperty($form_state, 'label_color'),
        ],
      ],
      'input_settings' => [
        '#type' => 'fieldset',
        '#title' => $this->t('Input Settings'),
        'input_font_family' => [
          '#type' => 'textfield',
          '#title' => $this->t('Font Family'),
          '#default_value' => $this->getElementProperty($form_state, 'input_font_family'),
        ],
        'input_font_size' => [
          '#type' => 'textfield',
          '#title' => $this->t('Font Size'),
          '#default_value' => $this->getElementProperty($form_state, 'input_font_size'),
        ],
        'input_color' => [
          '#type' => 'textfield',
          '#title' => $this->t('Color'),
          '#default_value' => $this->getElementProperty($form_state, 'input_color'),
        ],
      ],
    ];
  }

  /**
   * Build features section.
   */
  protected function buildFeaturesSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Feature Settings'),
      '#open' => FALSE,
      'dcc_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Dynamic Currency Conversion'),
        '#default_value' => $this->getElementProperty($form_state, 'dcc_enabled'),
      ],
      'three_ds_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable 3D Secure 2'),
        '#default_value' => $this->getElementProperty($form_state, 'three_ds_enabled'),
      ],
      'fraud_guard_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable FraudGuard'),
        '#default_value' => $this->getElementProperty($form_state, 'fraud_guard_enabled'),
      ],
      'auto_focus' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Auto Focus'),
        '#default_value' => $this->getElementProperty($form_state, 'auto_focus'),
      ],
      'bin_check_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable BIN Check'),
        '#default_value' => $this->getElementProperty($form_state, 'bin_check_enabled'),
      ],
    ];
  }

  /**
   * Build advanced section.
   */
  protected function buildAdvancedSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
      'timeout' => [
        '#type' => 'number',
        '#title' => $this->t('API Timeout'),
        '#default_value' => $this->getElementProperty($form_state, 'timeout'),
        '#min' => 5,
        '#max' => 300,
      ],
      'log_transactions' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Log Transactions'),
        '#default_value' => $this->getElementProperty($form_state, 'log_transactions'),
      ],
      'custom_callbacks' => [
        '#type' => 'textarea',
        '#title' => $this->t('Custom JavaScript Callbacks'),
        '#default_value' => $this->getElementProperty($form_state, 'custom_callbacks'),
        '#rows' => 5,
      ],
    ];
  }

  /**
   * Build test section.
   */
  protected function buildTestSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => FALSE,
      'test_connection' => [
        '#type' => 'submit',
        '#value' => $this->t('Test SecurePay Connection'),
        '#submit' => ['::testConnection'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::testConnectionAjax',
          'wrapper' => 'test-connection-result',
        ],
      ],
      'test_result' => [
        '#type' => 'markup',
        '#prefix' => '<div id="test-connection-result">',
        '#suffix' => '</div>',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $this->validateAmount($form_state);
    $this->validateCardTypes($form_state);
    $this->validateDccSettings($form_state);
  }

  /**
   * Validate amount field.
   */
  protected function validateAmount(FormStateInterface $form_state): void {
    $amount = $form_state->getValue('amount');
    if (!empty($amount) && !is_numeric($amount) && !preg_match('/\[.*\]/', $amount)) {
      $form_state->setErrorByName('amount', $this->t('Amount must be a number in cents or a token.'));
    }
  }

  /**
   * Validate card types.
   */
  protected function validateCardTypes(FormStateInterface $form_state): void {
    $card_types = array_filter($form_state->getValue('allowed_card_types') ?: []);
    if (empty($card_types)) {
      $form_state->setErrorByName('allowed_card_types', $this->t('At least one card type must be selected.'));
    }
  }

  /**
   * Validate DCC settings.
   */
  protected function validateDccSettings(FormStateInterface $form_state): void {
    $mode = $form_state->getValue('mode');
    $dcc_enabled = $form_state->getValue('dcc_enabled');
    if ($mode === 'dcc' && !$dcc_enabled) {
      $form_state->setErrorByName('dcc_enabled', $this->t('DCC must be enabled when using DCC mode.'));
    }
  }

  /**
   * Test connection submit handler.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    // Handled by AJAX callback
  }

  /**
   * Test connection AJAX callback.
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state) {
    try {
      $success = $this->securePayApi->testConnection();
      $message = $success 
        ? $this->t('✅ Connection successful!')
        : $this->t('❌ Connection failed. Please check your credentials.');
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

  /**
   * {@inheritdoc}
   */
  protected function formatHtmlItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    if (empty($value)) {
      return '';
    }

    $format = $this->getItemFormat($element);
    
    return match($format) {
      'value' => $value['transaction_id'] ?? '',
      'raw' => '<pre>' . htmlspecialchars(print_r($value, TRUE)) . '</pre>',
      default => $this->formatTransactionDetails($value),
    };
  }

  /**
   * Format transaction details for display.
   */
  protected function formatTransactionDetails(array $value): string {
    $items = [];
    $fields = [
      'transaction_id' => $this->t('Transaction ID'),
      'amount' => $this->t('Amount'),
      'status' => $this->t('Status'),
      'currency' => $this->t('Currency'),
      'gateway_response_code' => $this->t('Response Code'),
    ];

    foreach ($fields as $field => $label) {
      if (isset($value[$field])) {
        $items[] = '<strong>' . $label . ':</strong> ' . $value[$field];
      }
    }

    return implode('<br>', $items);
  }

  /**
   * {@inheritdoc}
   */
  public function preview() {
    return [
      '#type' => 'item',
      '#title' => $this->getPluginLabel(),
      '#markup' => $this->t('SecurePay payment element (preview mode)'),
    ];
  }

  /**
   * Get element property with fallback to global config.
   */
  protected function getElementProperty(FormStateInterface $form_state, string $property) {
    $element = $form_state->get('element');
    if (!empty($element['#' . $property])) {
      return $element['#' . $property];
    }
    
    return $this->configService->get($property) ?? $this->getDefaultProperty($property);
  }

  /**
   * Get currency options.
   */
  protected function getCurrencyOptions(): array {
    return [
      'AUD' => 'Australian Dollar (AUD)',
      'USD' => 'US Dollar (USD)',
      'EUR' => 'Euro (EUR)',
      'GBP' => 'British Pound (GBP)',
      'NZD' => 'New Zealand Dollar (NZD)',
      'CAD' => 'Canadian Dollar (CAD)',
      'JPY' => 'Japanese Yen (JPY)',
      'SGD' => 'Singapore Dollar (SGD)',
    ];
  }

  /**
   * Get card type options.
   */
  protected function getCardTypeOptions(): array {
    return [
      'visa' => $this->t('Visa'),
      'mastercard' => $this->t('Mastercard'),
      'amex' => $this->t('American Express'),
      'diners' => $this->t('Diners Club'),
    ];
  }

}