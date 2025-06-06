<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\webform_securepay\Exception\ConfigurationException;
use Drupal\webform_securepay\ValueObject\SecurePayConfig;

/**
 * Configuration service with improved validation and constants.
 */
class ConfigurationService {

  public const CONFIG_NAME = 'webform_securepay.settings';

  // Configuration keys
  public const CLIENT_ID = 'client_id';
  public const CLIENT_SECRET = 'client_secret';
  public const MERCHANT_CODE = 'merchant_code';
  public const ENVIRONMENT = 'environment';
  public const CURRENCY = 'currency';
  public const ORDER_ID_PREFIX = 'order_id_prefix';
  public const TIMEOUT = 'timeout';
  public const ALLOWED_CARD_TYPES = 'allowed_card_types';
  public const DCC_ENABLED = 'dcc_enabled';
  public const THREE_DS_ENABLED = 'three_ds_enabled';
  public const FRAUD_GUARD_ENABLED = 'fraud_guard_enabled';
  public const LOG_TRANSACTIONS = 'log_transactions';
  public const DEBUG_MODE = 'debug_mode';
  public const WEBHOOK_ENABLED = 'webhook_enabled';
  public const WEBHOOK_SECRET = 'webhook_secret';
  public const RATE_LIMIT_ENABLED = 'rate_limit_enabled';
  public const MAX_ATTEMPTS_PER_HOUR = 'max_attempts_per_hour';
  public const EMAIL_NOTIFICATIONS = 'email_notifications';
  public const NOTIFICATION_EMAIL = 'notification_email';
  public const DATA_RETENTION_DAYS = 'data_retention_days';

  // Validation constants
  public const MIN_TIMEOUT = 5;
  public const MAX_TIMEOUT = 300;

  private const DEFAULTS = [
    self::ENVIRONMENT => 'sandbox',
    self::CURRENCY => 'AUD',
    self::ORDER_ID_PREFIX => 'WF_',
    self::TIMEOUT => 30,
    self::ALLOWED_CARD_TYPES => ['visa', 'mastercard', 'amex', 'diners'],
    self::DCC_ENABLED => false,
    self::THREE_DS_ENABLED => false,
    self::FRAUD_GUARD_ENABLED => false,
    self::LOG_TRANSACTIONS => false,
    self::DEBUG_MODE => false,
    self::WEBHOOK_ENABLED => false,
    self::RATE_LIMIT_ENABLED => true,
    self::MAX_ATTEMPTS_PER_HOUR => 50,
    self::EMAIL_NOTIFICATIONS => false,
    self::DATA_RETENTION_DAYS => 90,
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Get configuration value with fallback to defaults.
   */
  public function get(string $key, mixed $default = null): mixed {
    return $this->configFactory->get(self::CONFIG_NAME)->get($key) 
      ?? $default 
      ?? self::DEFAULTS[$key] 
      ?? null;
  }

  /**
   * Set configuration value with validation.
   */
  public function set(string $key, mixed $value): void {
    $this->validateValue($key, $value);
    
    $this->configFactory->getEditable(self::CONFIG_NAME)
      ->set($key, $value)
      ->save();
  }

  /**
   * Check if SecurePay is properly configured.
   */
  public function isConfigured(): bool {
    $requiredFields = [self::CLIENT_ID, self::CLIENT_SECRET, self::MERCHANT_CODE];
    
    foreach ($requiredFields as $field) {
      if (empty($this->get($field))) {
        return false;
      }
    }
    
    return true;
  }

  /**
   * Get SecurePay configuration as value object.
   */
  public function getSecurePayConfig(): SecurePayConfig {
    if (!$this->isConfigured()) {
      throw ConfigurationException::missingConfiguration();
    }

    return new SecurePayConfig(
      clientId: $this->get(self::CLIENT_ID),
      clientSecret: $this->get(self::CLIENT_SECRET),
      merchantCode: $this->get(self::MERCHANT_CODE),
      environment: $this->get(self::ENVIRONMENT),
      currency: $this->get(self::CURRENCY),
      orderIdPrefix: $this->get(self::ORDER_ID_PREFIX),
      timeout: (int) $this->get(self::TIMEOUT),
      allowedCardTypes: $this->get(self::ALLOWED_CARD_TYPES),
      dccEnabled: (bool) $this->get(self::DCC_ENABLED),
      threeDSEnabled: (bool) $this->get(self::THREE_DS_ENABLED),
      fraudGuardEnabled: (bool) $this->get(self::FRAUD_GUARD_ENABLED),
      logTransactions: (bool) $this->get(self::LOG_TRANSACTIONS),
      debugMode: (bool) $this->get(self::DEBUG_MODE),
    );
  }

  /**
   * Get JavaScript settings for frontend.
   */
  public function getJavaScriptSettings(): array {
    if (!$this->isConfigured()) {
      return [];
    }

    $config = $this->getSecurePayConfig();
    return $config->toJavaScriptSettings();
  }

  /**
   * Get form options for admin interface.
   */
  public static function getEnvironmentOptions(): array {
    return [
      'sandbox' => t('Sandbox (Testing)'),
      'live' => t('Live (Production)'),
    ];
  }

  public static function getCurrencyOptions(): array {
    return [
      'AUD' => t('Australian Dollar'),
      'USD' => t('US Dollar'),
      'EUR' => t('Euro'),
      'GBP' => t('British Pound'),
      'NZD' => t('New Zealand Dollar'),
      'CAD' => t('Canadian Dollar'),
      'JPY' => t('Japanese Yen'),
      'SGD' => t('Singapore Dollar'),
    ];
  }

  public static function getCardTypeOptions(): array {
    return [
      'visa' => t('Visa'),
      'mastercard' => t('Mastercard'),
      'amex' => t('American Express'),
      'diners' => t('Diners Club'),
    ];
  }

  /**
   * Validate configuration value before saving.
   */
  private function validateValue(string $key, mixed $value): void {
    switch ($key) {
      case self::ENVIRONMENT:
        if (!array_key_exists($value, self::getEnvironmentOptions())) {
          throw ConfigurationException::invalidEnvironment($value);
        }
        break;

      case self::TIMEOUT:
        if ($value < self::MIN_TIMEOUT || $value > self::MAX_TIMEOUT) {
          throw ConfigurationException::invalidTimeout($value);
        }
        break;

      case self::CURRENCY:
        if (!array_key_exists($value, self::getCurrencyOptions())) {
          throw new ConfigurationException("Unsupported currency: {$value}");
        }
        break;

      case self::ALLOWED_CARD_TYPES:
        if (empty($value) || !is_array($value)) {
          throw ConfigurationException::noCardTypesConfigured();
        }
        $validTypes = array_keys(self::getCardTypeOptions());
        $invalidTypes = array_diff($value, $validTypes);
        if (!empty($invalidTypes)) {
          throw new ConfigurationException("Invalid card types: " . implode(', ', $invalidTypes));
        }
        break;
    }
  }
}