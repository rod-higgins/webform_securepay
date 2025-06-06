<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Consolidated service for building forms and elements.
 */
class FormBuilderService {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigurationService $configService,
    private readonly SecurePayApiServiceInterface $apiService,
  ) {}

  /**
   * Build authentication form section.
   */
  public function buildAuthenticationSection(): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Authentication Settings'),
      '#description' => $this->t('OAuth 2.0 credentials from your SecurePay merchant dashboard.'),
      ConfigurationService::CLIENT_ID => [
        '#type' => 'textfield',
        '#title' => $this->t('Client ID'),
        '#default_value' => $this->configService->get(ConfigurationService::CLIENT_ID),
        '#required' => true,
        '#maxlength' => 255,
        '#description' => $this->t('Your SecurePay OAuth 2.0 Client ID.'),
      ],
      ConfigurationService::CLIENT_SECRET => [
        '#type' => 'password',
        '#title' => $this->t('Client Secret'),
        '#description' => $this->configService->get(ConfigurationService::CLIENT_SECRET) 
          ? $this->t('Leave empty to keep current secret.')
          : $this->t('Enter your OAuth 2.0 client secret.'),
        '#maxlength' => 255,
      ],
      ConfigurationService::MERCHANT_CODE => [
        '#type' => 'textfield',
        '#title' => $this->t('Merchant Code'),
        '#default_value' => $this->configService->get(ConfigurationService::MERCHANT_CODE),
        '#required' => true,
        '#maxlength' => 255,
        '#description' => $this->t('Your SecurePay merchant identifier.'),
      ],
      ConfigurationService::ENVIRONMENT => [
        '#type' => 'select',
        '#title' => $this->t('Environment'),
        '#options' => ConfigurationService::getEnvironmentOptions(),
        '#default_value' => $this->configService->get(ConfigurationService::ENVIRONMENT),
        '#required' => true,
        '#description' => $this->t('Use sandbox for testing, live for production.'),
      ],
    ];
  }

  /**
   * Build payment settings form section.
   */
  public function buildPaymentSection(): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
      ConfigurationService::CURRENCY => [
        '#type' => 'select',
        '#title' => $this->t('Default Currency'),
        '#options' => ConfigurationService::getCurrencyOptions(),
        '#default_value' => $this->configService->get(ConfigurationService::CURRENCY),
        '#required' => true,
        '#description' => $this->t('Default currency for payments.'),
      ],
      ConfigurationService::ORDER_ID_PREFIX => [
        '#type' => 'textfield',
        '#title' => $this->t('Order ID Prefix'),
        '#default_value' => $this->configService->get(ConfigurationService::ORDER_ID_PREFIX),
        '#maxlength' => 10,
        '#description' => $this->t('Prefix for generated order IDs.'),
      ],
      ConfigurationService::ALLOWED_CARD_TYPES => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed Card Types'),
        '#options' => ConfigurationService::getCardTypeOptions(),
        '#default_value' => $this->configService->get(ConfigurationService::ALLOWED_CARD_TYPES),
        '#required' => true,
        '#description' => $this->t('Select which card types to accept.'),
      ],
    ];
  }

  /**
   * Build features form section.
   */
  public function buildFeaturesSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Feature Settings'),
      '#open' => false,
      ConfigurationService::DCC_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Dynamic Currency Conversion'),
        '#default_value' => $this->configService->get(ConfigurationService::DCC_ENABLED),
        '#description' => $this->t('Allow customers to pay in their card currency.'),
      ],
      ConfigurationService::THREE_DS_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable 3D Secure 2'),
        '#default_value' => $this->configService->get(ConfigurationService::THREE_DS_ENABLED),
        '#description' => $this->t('Enhanced authentication for fraud protection.'),
      ],
      ConfigurationService::FRAUD_GUARD_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable FraudGuard'),
        '#default_value' => $this->configService->get(ConfigurationService::FRAUD_GUARD_ENABLED),
        '#description' => $this->t('Advanced fraud detection system.'),
      ],
    ];
  }

  /**
   * Build advanced settings form section.
   */
  public function buildAdvancedSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => false,
      ConfigurationService::TIMEOUT => [
        '#type' => 'number',
        '#title' => $this->t('API Timeout (seconds)'),
        '#default_value' => $this->configService->get(ConfigurationService::TIMEOUT),
        '#min' => ConfigurationService::MIN_TIMEOUT,
        '#max' => ConfigurationService::MAX_TIMEOUT,
        '#description' => $this->t('Timeout for API requests (5-300 seconds).'),
      ],
      ConfigurationService::LOG_TRANSACTIONS => [
        '#type' => 'checkbox',
        '#title' => $this->t('Log Transactions'),
        '#default_value' => $this->configService->get(ConfigurationService::LOG_TRANSACTIONS),
        '#description' => $this->t('Store transaction details in database.'),
      ],
      ConfigurationService::DEBUG_MODE => [
        '#type' => 'checkbox',
        '#title' => $this->t('Debug Mode'),
        '#description' => $this->t('<strong>Warning:</strong> Do not enable in production!'),
        '#default_value' => $this->configService->get(ConfigurationService::DEBUG_MODE),
      ],
      ConfigurationService::RATE_LIMIT_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Rate Limiting'),
        '#default_value' => $this->configService->get(ConfigurationService::RATE_LIMIT_ENABLED),
        '#description' => $this->t('Limit payment attempts per IP address.'),
      ],
      ConfigurationService::MAX_ATTEMPTS_PER_HOUR => [
        '#type' => 'number',
        '#title' => $this->t('Max Attempts per Hour'),
        '#default_value' => $this->configService->get(ConfigurationService::MAX_ATTEMPTS_PER_HOUR),
        '#min' => 1,
        '#max' => 1000,
        '#states' => [
          'visible' => [
            ':input[name="' . ConfigurationService::RATE_LIMIT_ENABLED . '"]' => ['checked' => true],
          ],
        ],
      ],
    ];
  }

  /**
   * Build test connection form section.
   */
  public function buildTestSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => false,
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
   * Build element settings for JavaScript.
   */
  public function buildElementSettings(array $element): array {
    $settings = [
      'paymentCallbackUrl' => '/webform/securepay/callback',
      'clientId' => $element['#client_id'] ?? $this->configService->get(ConfigurationService::CLIENT_ID),
      'merchantCode' => $element['#merchant_code'] ?? $this->configService->get(ConfigurationService::MERCHANT_CODE),
      'environment' => $element['#environment'] ?? $this->configService->get(ConfigurationService::ENVIRONMENT),
      'amount' => $element['#amount'] ?? 0,
      'currency' => $element['#currency'] ?? $this->configService->get(ConfigurationService::CURRENCY),
      'mode' => $element['#mode'] ?? 'checkout',
      'allowedCardTypes' => array_values(array_filter(
        $element['#allowed_card_types'] ?? $this->configService->get(ConfigurationService::ALLOWED_CARD_TYPES)
      )),
    ];

    // Add styling options
    $styleKeys = [
      'background_color', 'label_font_family', 'label_font_size', 'label_color',
      'input_font_family', 'input_font_size', 'input_color',
    ];
    
    foreach ($styleKeys as $key) {
      if (isset($element['#' . $key])) {
        $settings[$key] = $element['#' . $key];
      }
    }

    // Add feature flags
    $featureKeys = [
      'dcc_enabled', 'three_ds_enabled', 'fraud_guard_enabled',
      'show_card_icons', 'auto_focus', 'bin_check_enabled',
    ];
    
    foreach ($featureKeys as $key) {
      $settings[$key] = $element['#' . $key] ?? $this->configService->get($key);
    }

    return $settings;
  }

  /**
   * Build form elements for the payment interface.
   */
  public function buildFormElements(string $containerId, array $element, array $settings): array {
    $elements = [
      'securepay_container' => [
        '#type' => 'markup',
        '#markup' => '<div id="' . $containerId . '" class="securepay-ui-container"></div>',
      ],
      'payment_button' => [
        '#type' => 'submit',
        '#value' => $this->t('Process Payment'),
        '#name' => 'securepay_' . $element['#name'],
        '#attributes' => [
          'class' => ['webform-securepay-button'],
          'data-container' => $containerId,
        ],
      ],
      'result' => [
        '#type' => 'markup',
        '#markup' => '<div class="webform-securepay-result" style="display: none;"></div>',
      ],
    ];

    // Add reset button if configured
    if ($element['#show_reset_button'] ?? true) {
      $elements['reset_button'] = [
        '#type' => 'button',
        '#value' => $this->t('Reset'),
        '#attributes' => [
          'class' => ['webform-securepay-reset'],
          'data-container' => $containerId,
        ],
      ];
    }

    // Add loading indicator
    $elements['loading_indicator'] = [
      '#type' => 'markup',
      '#markup' => '<div class="loading-indicator" style="display: none;">
        <span class="loading-text">' . $this->t('Processing payment...') . '</span>
      </div>',
    ];

    return $elements;
  }

  /**
   * Handle special payment modes (DCC, 3DS2).
   */
  public function handleSpecialModes(array &$settings): void {
    // Handle DCC mode
    if ($settings['mode'] === 'dcc' && $settings['dcc_enabled']) {
      $orderData = $this->apiService->initiatePaymentOrder(
        $settings['amount'], 
        'DYNAMIC_CURRENCY_CONVERSION',
        'WF_DCC_' . time()
      );
      
      if ($orderData) {
        $settings['orderToken'] = $orderData['orderToken'];
        $settings['orderId'] = $orderData['orderId'];
      }
    }

    // Handle 3DS2 mode
    if ($settings['three_ds_enabled']) {
      $orderData = $this->apiService->initiatePaymentOrder(
        $settings['amount'], 
        'THREED_SECURE',
        'WF_3DS2_' . time()
      );
      
      if ($orderData && isset($orderData['threedSecureDetails'])) {
        $settings = array_merge($settings, [
          'threeDSOrderToken' => $orderData['orderToken'],
          'threeDSClientId' => $orderData['threedSecureDetails']['providerClientId'],
          'threeDSSessionId' => $orderData['threedSecureDetails']['sessionId'],
          'threeDSSimpleToken' => $orderData['threedSecureDetails']['simpleToken'],
          'threeDSSdkUrl' => $this->apiService->getThreeDS2SdkUrl(),
        ]);
      }
    }
  }

  /**
   * Build attachments for the element.
   */
  public function buildAttachments(array $settings): array {
    return [
      'html_head' => [
        [
          [
            '#tag' => 'script',
            '#attributes' => [
              'id' => 'securepay-ui-js',
              'src' => $this->apiService->getUiSdkUrl(),
              'type' => 'text/javascript',
            ],
          ],
          'webform_securepay_ui_sdk',
        ]
      ],
      'library' => ['webform_securepay/webform_securepay'],
      'drupalSettings' => [
        'webformSecurePay' => $settings,
      ],
    ];
  }

  /**
   * Validate form values according to business rules.
   */
  public function validateFormValues(array $values): array {
    $errors = [];

    // Validate card types
    $cardTypes = array_filter($values[ConfigurationService::ALLOWED_CARD_TYPES] ?? []);
    if (empty($cardTypes)) {
      $errors[ConfigurationService::ALLOWED_CARD_TYPES] = $this->t('At least one card type must be selected.');
    }

    // Validate timeout
    $timeout = $values[ConfigurationService::TIMEOUT] ?? 0;
    if ($timeout < ConfigurationService::MIN_TIMEOUT || $timeout > ConfigurationService::MAX_TIMEOUT) {
      $errors[ConfigurationService::TIMEOUT] = $this->t('Timeout must be between @min and @max seconds.', [
        '@min' => ConfigurationService::MIN_TIMEOUT,
        '@max' => ConfigurationService::MAX_TIMEOUT,
      ]);
    }

    return $errors;
  }

  /**
   * Process form submission values.
   */
  public function processSubmissionValues(array $values): array {
    // Filter empty card types
    if (isset($values[ConfigurationService::ALLOWED_CARD_TYPES])) {
      $values[ConfigurationService::ALLOWED_CARD_TYPES] = array_values(
        array_filter($values[ConfigurationService::ALLOWED_CARD_TYPES])
      );
    }

    // Convert checkboxes to boolean
    $booleanFields = [
      ConfigurationService::DCC_ENABLED,
      ConfigurationService::THREE_DS_ENABLED,
      ConfigurationService::FRAUD_GUARD_ENABLED,
      ConfigurationService::LOG_TRANSACTIONS,
      ConfigurationService::DEBUG_MODE,
      ConfigurationService::RATE_LIMIT_ENABLED,
      ConfigurationService::EMAIL_NOTIFICATIONS,
    ];

    foreach ($booleanFields as $field) {
      if (isset($values[$field])) {
        $values[$field] = (bool) $values[$field];
      }
    }

    return $values;
  }
}