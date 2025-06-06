<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\webform_securepay\ValueObject\SecurePayConfig;
use Drupal\webform_securepay\Exception\ConfigurationException;

/**
 * Configuration service with constants and clean separation of concerns.
 */
class ConfigurationService {

  public const CONFIG_NAME = 'webform_securepay.settings';

  // Core configuration keys
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

  // Additional configuration keys
  public const WEBHOOK_ENABLED = 'webhook_enabled';
  public const WEBHOOK_SECRET = 'webhook_secret';
  public const RATE_LIMIT_ENABLED = 'rate_limit_enabled';
  public const MAX_ATTEMPTS_PER_HOUR = 'max_attempts_per_hour';
  public const EMAIL_NOTIFICATIONS = 'email_notifications';
  public const NOTIFICATION_EMAIL = 'notification_email';
  public const DATA_RETENTION_DAYS = 'data_retention_days';

  // Constants
  public const DEFAULT_ENVIRONMENT = 'sandbox';
  public const DEFAULT_CURRENCY = 'AUD';
  public const DEFAULT_ORDER_PREFIX = 'WF_';
  public const DEFAULT_TIMEOUT = 30;
  public const DEFAULT_MAX_ATTEMPTS = 50;
  public const DEFAULT_RETENTION_DAYS = 90;
  public const MIN_TIMEOUT = 5;
  public const MAX_TIMEOUT = 300;

  // Supported values
  public const ENVIRONMENTS = ['sandbox', 'live'];
  public const CURRENCIES = [
    'AUD' => 'Australian Dollar',
    'USD' => 'US Dollar', 
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'NZD' => 'New Zealand Dollar',
    'CAD' => 'Canadian Dollar',
    'JPY' => 'Japanese Yen',
    'SGD' => 'Singapore Dollar',
  ];
  public const CARD_TYPES = [
    'visa' => 'Visa',
    'mastercard' => 'Mastercard',
    'amex' => 'American Express',
    'diners' => 'Diners Club',
  ];

  private const DEFAULTS = [
    self::ENVIRONMENT => self::DEFAULT_ENVIRONMENT,
    self::CURRENCY => self::DEFAULT_CURRENCY,
    self::ORDER_ID_PREFIX => self::DEFAULT_ORDER_PREFIX,
    self::TIMEOUT => self::DEFAULT_TIMEOUT,
    self::ALLOWED_CARD_TYPES => ['visa', 'mastercard', 'amex', 'diners'],
    self::DCC_ENABLED => false,
    self::THREE_DS_ENABLED => false,
    self::FRAUD_GUARD_ENABLED => false,
    self::LOG_TRANSACTIONS => false,
    self::DEBUG_MODE => false,
    self::WEBHOOK_ENABLED => false,
    self::RATE_LIMIT_ENABLED => true,
    self::MAX_ATTEMPTS_PER_HOUR => self::DEFAULT_MAX_ATTEMPTS,
    self::EMAIL_NOTIFICATIONS => false,
    self::DATA_RETENTION_DAYS => self::DEFAULT_RETENTION_DAYS,
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function get(string $key, mixed $default = null): mixed {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    return $config->get($key) ?? $default ?? self::DEFAULTS[$key] ?? null;
  }

  public function set(string $key, mixed $value): void {
    $this->validateConfigValue($key, $value);
    
    $this->configFactory->getEditable(self::CONFIG_NAME)
      ->set($key, $value)
      ->save();
  }

  public function getSecurePayConfig(): SecurePayConfig {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    
    return new SecurePayConfig(
      clientId: $config->get(self::CLIENT_ID) ?? '',
      clientSecret: $config->get(self::CLIENT_SECRET) ?? '',
      merchantCode: $config->get(self::MERCHANT_CODE) ?? '',
      environment: $config->get(self::ENVIRONMENT) ?? self::DEFAULT_ENVIRONMENT,
      currency: $config->get(self::CURRENCY) ?? self::DEFAULT_CURRENCY,
      orderIdPrefix: $config->get(self::ORDER_ID_PREFIX) ?? self::DEFAULT_ORDER_PREFIX,
      timeout: $config->get(self::TIMEOUT) ?? self::DEFAULT_TIMEOUT,
      allowedCardTypes: $config->get(self::ALLOWED_CARD_TYPES) ?? self::DEFAULTS[self::ALLOWED_CARD_TYPES],
      dccEnabled: $config->get(self::DCC_ENABLED) ?? false,
      threeDSEnabled: $config->get(self::THREE_DS_ENABLED) ?? false,
      fraudGuardEnabled: $config->get(self::FRAUD_GUARD_ENABLED) ?? false,
      logTransactions: $config->get(self::LOG_TRANSACTIONS) ?? false,
      debugMode: $config->get(self::DEBUG_MODE) ?? false,
    );
  }

  public function isConfigured(): bool {
    try {
      $this->getSecurePayConfig();
      return true;
    }
    catch (ConfigurationException) {
      return false;
    }
  }

  public function validateConfiguration(): void {
    $this->getSecurePayConfig(); // Will throw exception if invalid
  }

  /**
   * Get configuration for JavaScript settings.
   */
  public function getJavaScriptSettings(): array {
    $config = $this->getSecurePayConfig();
    
    return [
      'clientId' => $config->clientId,
      'merchantCode' => $config->merchantCode,
      'environment' => $config->environment,
      'currency' => $config->currency,
      'allowedCardTypes' => $config->allowedCardTypes,
      'dccEnabled' => $config->dccEnabled,
      'threeDSEnabled' => $config->threeDSEnabled,
      'fraudGuardEnabled' => $config->fraudGuardEnabled,
      'endpoints' => $config->getApiEndpoints(),
    ];
  }

  /**
   * Get authentication settings with security consideration.
   */
  public function getAuthSettings(): array {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    
    return [
      self::CLIENT_ID => $config->get(self::CLIENT_ID) ?? '',
      self::CLIENT_SECRET => $config->get(self::CLIENT_SECRET) ? '[SET]' : '',
      self::MERCHANT_CODE => $config->get(self::MERCHANT_CODE) ?? '',
      self::ENVIRONMENT => $config->get(self::ENVIRONMENT) ?? self::DEFAULT_ENVIRONMENT,
    ];
  }

  /**
   * Get all available currency options.
   */
  public static function getCurrencyOptions(): array {
    return self::CURRENCIES;
  }

  /**
   * Get all available card type options.
   */
  public static function getCardTypeOptions(): array {
    return self::CARD_TYPES;
  }

  /**
   * Get all available environment options.
   */
  public static function getEnvironmentOptions(): array {
    return [
      'sandbox' => 'Sandbox (Testing)',
      'live' => 'Live (Production)',
    ];
  }

  /**
   * Validate configuration value before setting.
   */
  private function validateConfigValue(string $key, mixed $value): void {
    switch ($key) {
      case self::ENVIRONMENT:
        if (!in_array($value, self::ENVIRONMENTS, true)) {
          throw ConfigurationException::invalidEnvironment($value);
        }
        break;

      case self::TIMEOUT:
        if (!is_int($value) || $value < self::MIN_TIMEOUT || $value > self::MAX_TIMEOUT) {
          throw ConfigurationException::invalidTimeout($value);
        }
        break;

      case self::CURRENCY:
        if (!array_key_exists($value, self::CURRENCIES)) {
          throw new ConfigurationException("Invalid currency: {$value}");
        }
        break;

      case self::ALLOWED_CARD_TYPES:
        if (!is_array($value) || empty($value)) {
          throw ConfigurationException::noCardTypesConfigured();
        }
        $invalidTypes = array_diff($value, array_keys(self::CARD_TYPES));
        if (!empty($invalidTypes)) {
          throw new ConfigurationException('Invalid card types: ' . implode(', ', $invalidTypes));
        }
        break;
    }
  }
}