<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\webform_securepay\Exception\ConfigurationException;
use Drupal\webform_securepay\ValueObject\SecurePayConfig;

/**
 * Simplified configuration service with centralized management.
 */
class ConfigurationService {

  public const CONFIG_NAME = 'webform_securepay.settings';

  private const DEFAULTS = [
    'environment' => 'sandbox',
    'currency' => 'AUD',
    'order_id_prefix' => 'WF_',
    'timeout' => 30,
    'allowed_card_types' => ['visa', 'mastercard', 'amex', 'diners'],
    'dcc_enabled' => false,
    'three_ds_enabled' => false,
    'fraud_guard_enabled' => false,
    'log_transactions' => false,
    'debug_mode' => false,
    'webhook_enabled' => false,
    'rate_limit_enabled' => true,
    'max_attempts_per_hour' => 50,
    'email_notifications' => false,
    'data_retention_days' => 90,
  ];

  private const OPTIONS = [
    'environments' => ['sandbox' => 'Sandbox (Testing)', 'live' => 'Live (Production)'],
    'currencies' => [
      'AUD' => 'Australian Dollar', 'USD' => 'US Dollar', 'EUR' => 'Euro',
      'GBP' => 'British Pound', 'NZD' => 'New Zealand Dollar', 'CAD' => 'Canadian Dollar',
      'JPY' => 'Japanese Yen', 'SGD' => 'Singapore Dollar',
    ],
    'card_types' => [
      'visa' => 'Visa', 'mastercard' => 'Mastercard',
      'amex' => 'American Express', 'diners' => 'Diners Club',
    ],
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function get(string $key, mixed $default = null): mixed {
    return $this->configFactory->get(self::CONFIG_NAME)->get($key) 
      ?? $default 
      ?? self::DEFAULTS[$key] 
      ?? null;
  }

  public function set(string $key, mixed $value): void {
    $this->configFactory->getEditable(self::CONFIG_NAME)
      ->set($key, $value)
      ->save();
  }

  public function isConfigured(): bool {
    $requiredFields = ['client_id', 'client_secret', 'merchant_code'];
    
    foreach ($requiredFields as $field) {
      if (empty($this->get($field))) {
        return false;
      }
    }
    
    return true;
  }

  public function getSecurePayConfig(): SecurePayConfig {
    if (!$this->isConfigured()) {
      throw new ConfigurationException('SecurePay is not properly configured');
    }

    return new SecurePayConfig(
      clientId: $this->get('client_id'),
      clientSecret: $this->get('client_secret'),
      merchantCode: $this->get('merchant_code'),
      environment: $this->get('environment'),
      currency: $this->get('currency'),
      orderIdPrefix: $this->get('order_id_prefix'),
      timeout: (int) $this->get('timeout'),
      allowedCardTypes: $this->get('allowed_card_types'),
      dccEnabled: (bool) $this->get('dcc_enabled'),
      threeDSEnabled: (bool) $this->get('three_ds_enabled'),
      fraudGuardEnabled: (bool) $this->get('fraud_guard_enabled'),
      logTransactions: (bool) $this->get('log_transactions'),
      debugMode: (bool) $this->get('debug_mode'),
    );
  }

  public function getJavaScriptSettings(): array {
    $config = $this->getSecurePayConfig();
    
    return [
      'clientId' => $config->clientId,
      'merchantCode' => $config->merchantCode,
      'environment' => $config->environment,
      'currency' => $config->currency,
      'allowedCardTypes' => array_values($config->allowedCardTypes),
      'dccEnabled' => $config->dccEnabled,
      'threeDSEnabled' => $config->threeDSEnabled,
      'endpoints' => $config->getApiEndpoints(),
    ];
  }

  /**
   * Get form options for admin interface.
   */
  public function getFormOptions(string $type): array {
    return self::OPTIONS[$type] ?? [];
  }

  /**
   * Validate configuration value before saving.
   */
  public function validateValue(string $key, mixed $value): void {
    match($key) {
      'environment' => $this->validateEnvironment($value),
      'timeout' => $this->validateTimeout($value),
      'currency' => $this->validateCurrency($value),
      'allowed_card_types' => $this->validateCardTypes($value),
      default => null,
    };
  }

  private function validateEnvironment(string $environment): void {
    if (!array_key_exists($environment, self::OPTIONS['environments'])) {
      throw new ConfigurationException("Invalid environment: {$environment}");
    }
  }

  private function validateTimeout(int $timeout): void {
    if ($timeout < 5 || $timeout > 300) {
      throw new ConfigurationException("Timeout must be between 5 and 300 seconds");
    }
  }

  private function validateCurrency(string $currency): void {
    if (!array_key_exists($currency, self::OPTIONS['currencies'])) {
      throw new ConfigurationException("Unsupported currency: {$currency}");
    }
  }

  private function validateCardTypes(array $cardTypes): void {
    if (empty($cardTypes)) {
      throw new ConfigurationException("At least one card type must be selected");
    }

    $invalidTypes = array_diff($cardTypes, array_keys(self::OPTIONS['card_types']));
    if (!empty($invalidTypes)) {
      throw new ConfigurationException("Invalid card types: " . implode(', ', $invalidTypes));
    }
  }
}