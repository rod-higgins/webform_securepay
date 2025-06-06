<?php

namespace Drupal\webform_securepay\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform_securepay\Service\SecurePayApiService;

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The SecurePay API service.
   *
   * @var \Drupal\webform_securepay\Service\SecurePayApiService
   */
  protected $securePayApi;

  /**
   * Constructs a WebformSecurePay object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, SecurePayApiService $securepay_api) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->securePayApi = $securepay_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('webform_securepay.api')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineDefaultProperties() {
    return [
      // Authentication & Basic Settings
      'client_id' => '',
      'client_secret' => '',
      'merchant_code' => '',
      'environment' => 'sandbox',
      
      // Payment Settings
      'amount' => '',
      'currency' => 'AUD',
      'mode' => 'checkout', // 'checkout' or 'dcc'
      'order_id_prefix' => 'WF_',
      
      // Card Settings
      'allowed_card_types' => ['visa', 'mastercard', 'amex', 'diners'],
      'show_card_icons' => TRUE,
      
      // UI Style Settings
      'background_color' => 'rgba(255, 255, 255, 0.1)',
      'label_font_family' => 'Arial, Helvetica, sans-serif',
      'label_font_size' => '1rem',
      'label_color' => '#333',
      'input_font_family' => 'Arial, Helvetica, sans-serif',
      'input_font_size' => '1rem',
      'input_color' => '#333',
      
      // Feature Flags
      'dcc_enabled' => FALSE,
      'three_ds_enabled' => FALSE,
      'fraud_guard_enabled' => FALSE,
      'auto_focus' => FALSE,
      'bin_check_enabled' => FALSE,
      
      // Advanced Settings
      'timeout' => 30,
      'log_transactions' => FALSE,
      
      // Callbacks & Events
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
    $config = $this->configFactory->get('webform_securepay.settings');
    $properties = parent::getDefaultProperties();
    
    // Override with global settings if element properties are empty
    $global_mappings = [
      'client_id' => 'client_id',
      'client_secret' => 'client_secret',
      'merchant_code' => 'merchant_code',
      'environment' => 'environment',
      'currency' => 'currency',
    ];
    
    foreach ($global_mappings as $property => $config_key) {
      if (empty($properties[$property])) {
        $properties[$property] = $config->get($config_key) ?? '';
      }
    }
    
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $config = $this->configFactory->get('webform_securepay.settings');

    // Authentication Settings
    $form['authentication'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Authentication Settings'),
      '#description' => $this->t('Configure SecurePay authentication. Leave fields empty to use global defaults.'),
    ];

    $form['authentication']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#description' => $this->t('SecurePay Client ID. Global default: @default', [
        '@default' => $config->get('client_id') ?: $this->t('Not set'),
      ]),
      '#default_value' => $this->getElementProperty($form_state, 'client_id'),
      '#maxlength' => 255,
    ];

    $form['authentication']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->t('SecurePay Client Secret. Global default: @default', [
        '@default' => $config->get('client_secret') ? $this->t('Set') : $this->t('Not set'),
      ]),
      '#default_value' => $this->getElementProperty($form_state, 'client_secret'),
      '#maxlength' => 255,
    ];

    $form['authentication']['merchant_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Code'),
      '#description' => $this->t('SecurePay Merchant Code.'),
      '#default_value' => $this->getElementProperty($form_state, 'merchant_code'),
      '#maxlength' => 255,
    ];

    $form['authentication']['environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#description' => $this->t('SecurePay environment to use.'),
      '#options' => [
        'sandbox' => $this->t('Sandbox (Testing)'),
        'live' => $this->t('Live (Production)'),
      ],
      '#default_value' => $this->getElementProperty($form_state, 'environment'),
      '#required' => TRUE,
    ];

    // Payment Settings
    $form['payment'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
    ];

    $form['payment']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#description' => $this->t('Payment amount in cents (e.g., 1000 for $10.00). Can use tokens like [webform_submission:values:amount_field].'),
      '#default_value' => $this->getElementProperty($form_state, 'amount'),
      '#required' => TRUE,
    ];

    $form['payment']['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Currency'),
      '#description' => $this->t('Payment currency.'),
      '#options' => [
        'AUD' => 'Australian Dollar (AUD)',
        'USD' => 'US Dollar (USD)',
        'EUR' => 'Euro (EUR)',
        'GBP' => 'British Pound (GBP)',
        'NZD' => 'New Zealand Dollar (NZD)',
        'CAD' => 'Canadian Dollar (CAD)',
        'JPY' => 'Japanese Yen (JPY)',
        'SGD' => 'Singapore Dollar (SGD)',
        'CHF' => 'Swiss Franc (CHF)',
        'NOK' => 'Norwegian Krone (NOK)',
        'MYR' => 'Malaysian Ringgit (MYR)',
        'HKD' => 'Hong Kong Dollar (HKD)',
      ],
      '#default_value' => $this->getElementProperty($form_state, 'currency'),
      '#required' => TRUE,
    ];

    $form['payment']['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Payment Mode'),
      '#description' => $this->t('Checkout mode for standard payments, DCC mode for Dynamic Currency Conversion.'),
      '#options' => [
        'checkout' => $this->t('Checkout (Standard)'),
        'dcc' => $this->t('DCC (Dynamic Currency Conversion)'),
      ],
      '#default_value' => $this->getElementProperty($form_state, 'mode'),
    ];

    $form['payment']['order_id_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order ID Prefix'),
      '#description' => $this->t('Prefix for order IDs to ensure uniqueness.'),
      '#default_value' => $this->getElementProperty($form_state, 'order_id_prefix'),
      '#maxlength' => 10,
    ];

    // Card Settings
    $form['card'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Card Settings'),
    ];

    $form['card']['allowed_card_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed Card Types'),
      '#description' => $this->t('Select which card types to accept.'),
      '#options' => [
        'visa' => $this->t('Visa'),
        'mastercard' => $this->t('Mastercard'),
        'amex' => $this->t('American Express'),
        'diners' => $this->t('Diners Club'),
      ],
      '#default_value' => $this->getElementProperty($form_state, 'allowed_card_types'),
    ];

    $form['card']['show_card_icons'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Card Icons'),
      '#description' => $this->t('Display card type icons in the payment form.'),
      '#default_value' => $this->getElementProperty($form_state, 'show_card_icons'),
    ];

    // UI Style Settings
    $form['style'] = [
      '#type' => 'details',
      '#title' => $this->t('UI Style Settings'),
      '#open' => FALSE,
    ];

    $form['style']['background_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Background Color'),
      '#description' => $this->t('Background color for the payment form (e.g., rgba(255, 255, 255, 0.1), #ffffff, white).'),
      '#default_value' => $this->getElementProperty($form_state, 'background_color'),
    ];

    $form['style']['label_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Label Settings'),
    ];

    $form['style']['label_settings']['label_font_family'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label Font Family'),
      '#description' => $this->t('Font family for form labels.'),
      '#default_value' => $this->getElementProperty($form_state, 'label_font_family'),
    ];

    $form['style']['label_settings']['label_font_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label Font Size'),
      '#description' => $this->t('Font size for form labels (e.g., 1rem, 14px).'),
      '#default_value' => $this->getElementProperty($form_state, 'label_font_size'),
    ];

    $form['style']['label_settings']['label_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label Color'),
      '#description' => $this->t('Color for form labels.'),
      '#default_value' => $this->getElementProperty($form_state, 'label_color'),
    ];

    $form['style']['input_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Input Settings'),
    ];

    $form['style']['input_settings']['input_font_family'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Input Font Family'),
      '#description' => $this->t('Font family for form inputs.'),
      '#default_value' => $this->getElementProperty($form_state, 'input_font_family'),
    ];

    $form['style']['input_settings']['input_font_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Input Font Size'),
      '#description' => $this->t('Font size for form inputs (e.g., 1rem, 14px).'),
      '#default_value' => $this->getElementProperty($form_state, 'input_font_size'),
    ];

    $form['style']['input_settings']['input_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Input Color'),
      '#description' => $this->t('Color for form inputs.'),
      '#default_value' => $this->getElementProperty($form_state, 'input_color'),
    ];

    // Feature Settings
    $form['features'] = [
      '#type' => 'details',
      '#title' => $this->t('Feature Settings'),
      '#open' => FALSE,
    ];

    $form['features']['dcc_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Dynamic Currency Conversion'),
      '#description' => $this->t('Allow customers to pay in their card currency.'),
      '#default_value' => $this->getElementProperty($form_state, 'dcc_enabled'),
    ];

    $form['features']['three_ds_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable 3D Secure 2'),
      '#description' => $this->t('Enable 3D Secure 2 authentication for enhanced security.'),
      '#default_value' => $this->getElementProperty($form_state, 'three_ds_enabled'),
    ];

    $form['features']['fraud_guard_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable FraudGuard'),
      '#description' => $this->t('Enable fraud detection before processing payments.'),
      '#default_value' => $this->getElementProperty($form_state, 'fraud_guard_enabled'),
    ];

    $form['features']['auto_focus'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto Focus'),
      '#description' => $this->t('Automatically focus on the first field when the form loads.'),
      '#default_value' => $this->getElementProperty($form_state, 'auto_focus'),
    ];

    $form['features']['bin_check_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable BIN Check'),
      '#description' => $this->t('Enable Bank Identification Number checking for additional validation.'),
      '#default_value' => $this->getElementProperty($form_state, 'bin_check_enabled'),
    ];

    // Advanced Settings
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout'),
      '#description' => $this->t('Timeout for SecurePay API requests in seconds.'),
      '#default_value' => $this->getElementProperty($form_state, 'timeout'),
      '#min' => 5,
      '#max' => 300,
      '#step' => 1,
    ];

    $form['advanced']['log_transactions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log Transactions'),
      '#description' => $this->t('Log all SecurePay transactions for debugging purposes.'),
      '#default_value' => $this->getElementProperty($form_state, 'log_transactions'),
    ];

    $form['advanced']['custom_callbacks'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom JavaScript Callbacks'),
      '#description' => $this->t('Additional JavaScript code to execute on various events. Use $(element).on("securepay:eventName", function(event, data) { ... });'),
      '#default_value' => $this->getElementProperty($form_state, 'custom_callbacks'),
      '#rows' => 10,
    ];

    // Test Connection Button
    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => FALSE,
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

    return $form;
  }

  /**
   * Test connection submit handler.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    // This will be handled by the AJAX callback
  }

  /**
   * Test connection AJAX callback.
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state) {
    try {
      if ($this->securePayApi->testConnection()) {
        $message = $this->t('✅ Connection successful! SecurePay API is accessible.');
        $class = 'messages messages--status';
      } else {
        $message = $this->t('❌ Connection failed. Please check your credentials and settings.');
        $class = 'messages messages--error';
      }
    } catch (\Exception $e) {
      $message = $this->t('❌ Connection failed: @error', ['@error' => $e->getMessage()]);
      $class = 'messages messages--error';
    }

    return [
      '#type' => 'markup',
      '#markup' => '<div class="' . $class . '">' . $message . '</div>',
      '#prefix' => '<div id="test-connection-result">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate amount
    $amount = $form_state->getValue('amount');
    if (!empty($amount) && !is_numeric($amount) && !preg_match('/\[.*\]/', $amount)) {
      $form_state->setErrorByName('amount', $this->t('Amount must be a number in cents or a token.'));
    }

    // Validate DCC mode requirements
    $mode = $form_state->getValue('mode');
    $dcc_enabled = $form_state->getValue('dcc_enabled');
    if ($mode === 'dcc' && !$dcc_enabled) {
      $form_state->setErrorByName('dcc_enabled', $this->t('DCC must be enabled when using DCC mode.'));
    }

    // Validate card types
    $card_types = array_filter($form_state->getValue('allowed_card_types') ?: []);
    if (empty($card_types)) {
      $form_state->setErrorByName('allowed_card_types', $this->t('At least one card type must be selected.'));
    }
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
    switch ($format) {
      case 'value':
        return $value['transaction_id'] ?? '';
      
      case 'raw':
        return '<pre>' . htmlspecialchars(print_r($value, TRUE)) . '</pre>';
        
      default:
        $items = [];
        if (isset($value['transaction_id'])) {
          $items[] = '<strong>' . $this->t('Transaction ID') . ':</strong> ' . $value['transaction_id'];
        }
        if (isset($value['amount'])) {
          $items[] = '<strong>' . $this->t('Amount') . ':</strong> ' . $value['amount'];
        }
        if (isset($value['status'])) {
          $items[] = '<strong>' . $this->t('Status') . ':</strong> ' . $value['status'];
        }
        if (isset($value['currency'])) {
          $items[] = '<strong>' . $this->t('Currency') . ':</strong> ' . $value['currency'];
        }
        if (isset($value['gateway_response_code'])) {
          $items[] = '<strong>' . $this->t('Response Code') . ':</strong> ' . $value['gateway_response_code'];
        }
        return implode('<br>', $items);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function formatTextItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    if (empty($value)) {
      return '';
    }

    $items = [];
    if (isset($value['transaction_id'])) {
      $items[] = $this->t('Transaction ID') . ': ' . $value['transaction_id'];
    }
    if (isset($value['amount'])) {
      $items[] = $this->t('Amount') . ': ' . $value['amount'];
    }
    if (isset($value['status'])) {
      $items[] = $this->t('Status') . ': ' . $value['status'];
    }
    if (isset($value['currency'])) {
      $items[] = $this->t('Currency') . ': ' . $value['currency'];
    }
    return implode("\n", $items);
  }

  /**
   * {@inheritdoc}
   */
  public function preview() {
    return [
      '#type' => 'item',
      '#title' => $this->getPluginLabel(),
      '#markup' => $this->t('SecurePay payment element (preview mode) - Modern REST API with all features enabled'),
    ];
  }

  /**
   * Get element property with fallback to global config.
   */
  protected function getElementProperty(FormStateInterface $form_state, $property) {
    $element = $form_state->get('element');
    if (!empty($element['#' . $property])) {
      return $element['#' . $property];
    }
    
    $config = $this->configFactory->get('webform_securepay.settings');
    return $config->get($property) ?? $this->getDefaultProperty($property);
  }

}