<?php

namespace Drupal\webform_securepay\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\FormBuilderService;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;

/**
 * Provides a 'securepay' element for webforms.
 *
 * @WebformElement(
 *   id = "securepay",
 *   label = @Translation("SecurePay"),
 *   description = @Translation("Provides SecurePay payment processing with modern REST API."),
 *   category = @Translation("Payment"),
 * )
 */
class WebformSecurePay extends WebformElementBase implements ContainerFactoryPluginInterface {

  // Validation constants
  private const MIN_AMOUNT = 1;
  private const MAX_AMOUNT = 99999999;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigurationService $configService,
    private readonly FormBuilderService $formBuilder,
    private readonly SecurePayApiServiceInterface $apiService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.form_builder'),
      $container->get('webform_securepay.api')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineDefaultProperties() {
    return [
      // Payment settings
      'amount' => '',
      'currency' => '',
      'mode' => 'checkout',
      
      // Card settings
      'allowed_card_types' => [],
      'show_card_icons' => true,
      'show_reset_button' => true,
      
      // Feature flags
      'dcc_enabled' => null,
      'three_ds_enabled' => null,
      'fraud_guard_enabled' => null,
      
      // UI customization
      'background_color' => 'transparent',
      'label_font_family' => 'inherit',
      'label_font_size' => '1rem',
      'input_font_family' => 'inherit',
      'input_font_size' => '1rem',
      
      // Standard properties
      'title' => '',
      'description' => '',
      'required' => false,
    ] + parent::defineDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    $properties = parent::getDefaultProperties();
    
    // Merge with global configuration defaults
    $configMappings = [
      'currency' => ConfigurationService::CURRENCY,
      'allowed_card_types' => ConfigurationService::ALLOWED_CARD_TYPES,
      'dcc_enabled' => ConfigurationService::DCC_ENABLED,
      'three_ds_enabled' => ConfigurationService::THREE_DS_ENABLED,
      'fraud_guard_enabled' => ConfigurationService::FRAUD_GUARD_ENABLED,
    ];

    foreach ($configMappings as $property => $configKey) {
      if (empty($properties[$property])) {
        $properties[$property] = $this->configService->get($configKey);
      }
    }
    
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    // Check if SecurePay is configured
    if (!$this->configService->isConfigured()) {
      $form['configuration_warning'] = [
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('SecurePay is not configured. Please <a href="@url">configure SecurePay settings</a> first.', [
            '@url' => \Drupal::url('webform_securepay.admin_settings'),
          ]) . '</div>',
        '#weight' => -100,
      ];
    }

    $form['payment'] = $this->buildPaymentSection($form_state);
    $form['card'] = $this->buildCardSection($form_state);
    $form['features'] = $this->buildFeaturesSection($form_state);
    $form['ui'] = $this->buildUISection($form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $this->validateAmount($form_state);
    $this->validateCardTypes($form_state);
    $this->validateBusinessRules($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function preview() {
    return [
      '#type' => 'item',
      '#title' => $this->getPluginLabel(),
      '#markup' => $this->t('SecurePay payment element (preview mode)'),
      '#description' => $this->t('This element will display the SecurePay payment form when viewed by users.'),
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
      'raw' => '<pre>' . htmlspecialchars(print_r($value, true)) . '</pre>',
      default => $this->formatTransactionSummary($value),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getItemDefaultFormat() {
    return 'summary';
  }

  /**
   * {@inheritdoc}
   */
  public function getItemFormats() {
    return parent::getItemFormats() + [
      'summary' => $this->t('Transaction summary'),
      'value' => $this->t('Transaction ID only'),
      'raw' => $this->t('Raw data'),
    ];
  }

  /**
   * Build payment settings section.
   */
  private function buildPaymentSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
      'amount' => [
        '#type' => 'textfield',
        '#title' => $this->t('Amount'),
        '#description' => $this->t('Payment amount in cents or token like [webform_submission:values:amount_field]. Minimum @min cents, maximum @max cents.', [
          '@min' => number_format(self::MIN_AMOUNT),
          '@max' => number_format(self::MAX_AMOUNT),
        ]),
        '#default_value' => $this->getElementProperty($form_state, 'amount'),
        '#required' => true,
      ],
      'currency' => [
        '#type' => 'select',
        '#title' => $this->t('Currency'),
        '#options' => ConfigurationService::getCurrencyOptions(),
        '#default_value' => $this->getElementProperty($form_state, 'currency'),
        '#required' => true,
      ],
      'mode' => [
        '#type' => 'select',
        '#title' => $this->t('Payment Mode'),
        '#options' => [
          'checkout' => $this->t('Checkout (Standard)'),
          'dcc' => $this->t('DCC (Dynamic Currency Conversion)'),
        ],
        '#default_value' => $this->getElementProperty($form_state, 'mode'),
        '#description' => $this->t('DCC allows customers to pay in their card currency.'),
      ],
    ];
  }

  /**
   * Build card settings section.
   */
  private function buildCardSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Card Settings'),
      'allowed_card_types' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed Card Types'),
        '#options' => ConfigurationService::getCardTypeOptions(),
        '#default_value' => $this->getElementProperty($form_state, 'allowed_card_types'),
        '#required' => true,
        '#description' => $this->t('Select which card types to accept.'),
      ],
      'show_card_icons' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show Card Icons'),
        '#default_value' => $this->getElementProperty($form_state, 'show_card_icons'),
        '#description' => $this->t('Display card type icons in the payment form.'),
      ],
      'show_reset_button' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show Reset Button'),
        '#default_value' => $this->getElementProperty($form_state, 'show_reset_button'),
        '#description' => $this->t('Show a button to reset the payment form.'),
      ],
    ];
  }

  /**
   * Build features section.
   */
  private function buildFeaturesSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Feature Settings'),
      '#description' => $this->t('Override global feature settings for this element.'),
      '#open' => false,
      'dcc_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Dynamic Currency Conversion'),
        '#default_value' => $this->getElementProperty($form_state, 'dcc_enabled'),
        '#description' => $this->t('Allow customers to pay in their card currency.'),
      ],
      'three_ds_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable 3D Secure 2'),
        '#default_value' => $this->getElementProperty($form_state, 'three_ds_enabled'),
        '#description' => $this->t('Enhanced authentication for fraud protection.'),
      ],
      'fraud_guard_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable FraudGuard'),
        '#default_value' => $this->getElementProperty($form_state, 'fraud_guard_enabled'),
        '#description' => $this->t('Advanced fraud detection system.'),
      ],
    ];
  }

  /**
   * Build UI customization section.
   */
  private function buildUISection(FormStateInterface $form_state): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('UI Customization'),
      '#open' => false,
      'background_color' => [
        '#type' => 'textfield',
        '#title' => $this->t('Background Color'),
        '#default_value' => $this->getElementProperty($form_state, 'background_color'),
        '#description' => $this->t('CSS background color (e.g., #ffffff, rgba(255,255,255,0.1), transparent).'),
      ],
      'label_font_family' => [
        '#type' => 'textfield',
        '#title' => $this->t('Label Font Family'),
        '#default_value' => $this->getElementProperty($form_state, 'label_font_family'),
        '#description' => $this->t('CSS font family for labels (e.g., Arial, sans-serif).'),
      ],
      'label_font_size' => [
        '#type' => 'textfield',
        '#title' => $this->t('Label Font Size'),
        '#default_value' => $this->getElementProperty($form_state, 'label_font_size'),
        '#description' => $this->t('CSS font size for labels (e.g., 1rem, 14px).'),
      ],
      'input_font_family' => [
        '#type' => 'textfield',
        '#title' => $this->t('Input Font Family'),
        '#default_value' => $this->getElementProperty($form_state, 'input_font_family'),
        '#description' => $this->t('CSS font family for input fields.'),
      ],
      'input_font_size' => [
        '#type' => 'textfield',
        '#title' => $this->t('Input Font Size'),
        '#default_value' => $this->getElementProperty($form_state, 'input_font_size'),
        '#description' => $this->t('CSS font size for input fields.'),
      ],
    ];
  }

  /**
   * Validate amount field.
   */
  private function validateAmount(FormStateInterface $form_state): void {
    $amount = $form_state->getValue('amount');
    
    if (empty($amount)) {
      $form_state->setErrorByName('amount', $this->t('Amount is required.'));
      return;
    }

    // Allow tokens or numeric values
    if (!is_numeric($amount) && !preg_match('/\[.*\]/', $amount)) {
      $form_state->setErrorByName('amount', 
        $this->t('Amount must be a number in cents or a token.'));
      return;
    }

    // Validate numeric amounts
    if (is_numeric($amount)) {
      $amountInt = (int) $amount;
      if ($amountInt < self::MIN_AMOUNT || $amountInt > self::MAX_AMOUNT) {
        $form_state->setErrorByName('amount', 
          $this->t('Amount must be between @min and @max cents.', [
            '@min' => number_format(self::MIN_AMOUNT),
            '@max' => number_format(self::MAX_AMOUNT),
          ]));
      }
    }
  }

  /**
   * Validate card types selection.
   */
  private function validateCardTypes(FormStateInterface $form_state): void {
    $cardTypes = array_filter($form_state->getValue('allowed_card_types') ?: []);
    
    if (empty($cardTypes)) {
      $form_state->setErrorByName('allowed_card_types', 
        $this->t('At least one card type must be selected.'));
    }

    $validTypes = array_keys(ConfigurationService::getCardTypeOptions());
    $invalidTypes = array_diff($cardTypes, $validTypes);
    
    if (!empty($invalidTypes)) {
      $form_state->setErrorByName('allowed_card_types', 
        $this->t('Invalid card types: @types', [
          '@types' => implode(', ', $invalidTypes),
        ]));
    }
  }

  /**
   * Validate business rules.
   */
  private function validateBusinessRules(FormStateInterface $form_state): void {
    $mode = $form_state->getValue('mode');
    $dccEnabled = $form_state->getValue('dcc_enabled');
    
    // Validate DCC settings
    if ($mode === 'dcc' && !$dccEnabled) {
      $form_state->setErrorByName('dcc_enabled', 
        $this->t('DCC must be enabled when using DCC mode.'));
    }
  }

  /**
   * Get element property with fallback to configuration.
   */
  private function getElementProperty(FormStateInterface $form_state, string $property): mixed {
    $element = $form_state->get('element');
    if (!empty($element['#' . $property])) {
      return $element['#' . $property];
    }
    
    return $this->configService->get($property) ?? $this->getDefaultProperty($property);
  }

  /**
   * Format transaction data for display.
   */
  private function formatTransactionSummary(array $value): string {
    $items = [];
    $fields = [
      'transaction_id' => $this->t('Transaction ID'),
      'amount' => $this->t('Amount'),
      'status' => $this->t('Status'),
      'currency' => $this->t('Currency'),
    ];

    foreach ($fields as $field => $label) {
      if (isset($value[$field])) {
        $displayValue = $field === 'amount' 
          ? number_format($value[$field] / 100, 2) . ' ' . ($value['currency'] ?? 'AUD')
          : $value[$field];
        $items[] = '<strong>' . $label . ':</strong> ' . htmlspecialchars($displayValue);
      }
    }

    return implode('<br>', $items);
  }
}