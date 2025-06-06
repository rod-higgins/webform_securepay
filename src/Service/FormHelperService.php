<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform_securepay\Service\ConfigurationService;

/**
 * Service to build form sections and reduce form complexity.
 */
class FormHelperService {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigurationService $configService,
  ) {}

  /**
   * Build authentication form section.
   */
  public function buildAuthenticationSection(): array {
    $authSettings = $this->configService->getAuthSettings();

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
      ],
      ConfigurationService::CLIENT_SECRET => [
        '#type' => 'password',
        '#title' => $this->t('Client Secret'),
        '#description' => $this->configService->get(ConfigurationService::CLIENT_SECRET) 
          ? $this->t('Leave empty to keep current secret.')
          : $this->t('Enter your client secret.'),
        '#maxlength' => 255,
      ],
      ConfigurationService::MERCHANT_CODE => [
        '#type' => 'textfield',
        '#title' => $this->t('Merchant Code'),
        '#default_value' => $this->configService->get(ConfigurationService::MERCHANT_CODE),
        '#required' => true,
        '#maxlength' => 255,
      ],
      ConfigurationService::ENVIRONMENT => [
        '#type' => 'select',
        '#title' => $this->t('Environment'),
        '#options' => ConfigurationService::getEnvironmentOptions(),
        '#default_value' => $this->configService->get(ConfigurationService::ENVIRONMENT),
        '#required' => true,
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
      ],
      ConfigurationService::ORDER_ID_PREFIX => [
        '#type' => 'textfield',
        '#title' => $this->t('Order ID Prefix'),
        '#default_value' => $this->configService->get(ConfigurationService::ORDER_ID_PREFIX),
        '#maxlength' => 10,
      ],
      ConfigurationService::ALLOWED_CARD_TYPES => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed Card Types'),
        '#options' => ConfigurationService::getCardTypeOptions(),
        '#default_value' => $this->configService->get(ConfigurationService::ALLOWED_CARD_TYPES),
        '#required' => true,
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
      ],
      ConfigurationService::THREE_DS_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable 3D Secure 2'),
        '#default_value' => $this->configService->get(ConfigurationService::THREE_DS_ENABLED),
      ],
      ConfigurationService::FRAUD_GUARD_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable FraudGuard'),
        '#default_value' => $this->configService->get(ConfigurationService::FRAUD_GUARD_ENABLED),
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
      ],
      ConfigurationService::LOG_TRANSACTIONS => [
        '#type' => 'checkbox',
        '#title' => $this->t('Log Transactions'),
        '#default_value' => $this->configService->get(ConfigurationService::LOG_TRANSACTIONS),
      ],
      ConfigurationService::DEBUG_MODE => [
        '#type' => 'checkbox',
        '#title' => $this->t('Debug Mode'),
        '#description' => $this->t('<strong>Do not enable in production!</strong>'),
        '#default_value' => $this->configService->get(ConfigurationService::DEBUG_MODE),
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
    ];

    foreach ($booleanFields as $field) {
      if (isset($values[$field])) {
        $values[$field] = (bool) $values[$field];
      }
    }

    return $values;
  }
}