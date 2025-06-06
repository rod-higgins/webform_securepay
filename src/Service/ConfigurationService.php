<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\webform_securepay\ValueObject\SecurePayConfig;
use Drupal\webform_securepay\Exception\ConfigurationException;

/**
 * Simplified configuration service.
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
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function get(string $key, mixed $default = null): mixed {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    return $config->get($key) ?? $default ?? self::DEFAULTS[$key] ?? null;
  }

  public function set(string $key, mixed $value): void {
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
      environment: $config->get(self::ENVIRONMENT) ?? 'sandbox',
      currency: $config->get(self::CURRENCY) ?? 'AUD',
      orderIdPrefix: $config->get(self::ORDER_ID_PREFIX) ?? 'WF_',
      timeout: $config->get(self::TIMEOUT) ?? 30,
      allowedCardTypes: $config->get(self::ALLOWED_CARD_TYPES) ?? ['visa', 'mastercard', 'amex', 'diners'],
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
}