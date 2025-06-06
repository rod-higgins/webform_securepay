<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Configuration service for SecurePay settings.
 */
class ConfigurationService {

  public const CONFIG_NAME = 'webform_securepay.settings';
  
  // Environment constants
  public const ENVIRONMENT_SANDBOX = 'sandbox';
  public const ENVIRONMENT_LIVE = 'live';
  
  // Currency constants
  public const CURRENCY_AUD = 'AUD';
  public const CURRENCY_USD = 'USD';
  public const CURRENCY_EUR = 'EUR';
  public const CURRENCY_GBP = 'GBP';
  
  // API endpoints
  private const SANDBOX_BASE_URL = 'https://api.payments.test.auspost.com.au';
  private const LIVE_BASE_URL = 'https://api.payments.auspost.com.au';
  
  // Required configuration fields
  private const REQUIRED_FIELDS = ['client_id', 'client_secret', 'merchant_code'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Get configuration value.
   */
  public function get(string $key, mixed $default = null): mixed {
    return $this->configFactory->get(self::CONFIG_NAME)->get($key) ?? $default;
  }

  /**
   * Set configuration value.
   */
  public function set(string $key, mixed $value): void {
    $this->configFactory->getEditable(self::CONFIG_NAME)
      ->set($key, $value)
      ->save();
  }

  /**
   * Check if SecurePay is configured.
   */
  public function isConfigured(): bool {
    foreach (self::REQUIRED_FIELDS as $field) {
      $value = $this->get($field);
      if (empty($value) || !is_string($value) || trim($value) === '') {
        return false;
      }
    }
    
    return true;
  }

  /**
   * Get API endpoints based on environment.
   */
  public function getApiEndpoints(): array {
    $isLive = $this->get('environment') === self::ENVIRONMENT_LIVE;
    $baseUrl = $isLive ? self::LIVE_BASE_URL : self::SANDBOX_BASE_URL;

    return [
      'auth' => $baseUrl . '/oauth/token',
      'api' => $baseUrl,
      'ui_sdk' => $baseUrl . '/ui/v2/dist/index.js',
    ];
  }

  /**
   * Get environment options for forms.
   */
  public static function getEnvironmentOptions(): array {
    return [
      self::ENVIRONMENT_SANDBOX => t('Sandbox (Testing)'),
      self::ENVIRONMENT_LIVE => t('Live (Production)'),
    ];
  }

  /**
   * Get currency options for forms.
   */
  public static function getCurrencyOptions(): array {
    return [
      self::CURRENCY_AUD => t('Australian Dollar'),
      self::CURRENCY_USD => t('US Dollar'),
      self::CURRENCY_EUR => t('Euro'),
      self::CURRENCY_GBP => t('British Pound'),
    ];
  }

  /**
   * Get valid environments.
   */
  public static function getValidEnvironments(): array {
    return [self::ENVIRONMENT_SANDBOX, self::ENVIRONMENT_LIVE];
  }

  /**
   * Get valid currencies.
   */
  public static function getValidCurrencies(): array {
    return [self::CURRENCY_AUD, self::CURRENCY_USD, self::CURRENCY_EUR, self::CURRENCY_GBP];
  }

  /**
   * Validate environment setting.
   */
  public function isValidEnvironment(string $environment): bool {
    return in_array($environment, self::getValidEnvironments(), true);
  }

  /**
   * Validate currency setting.
   */
  public function isValidCurrency(string $currency): bool {
    return in_array($currency, self::getValidCurrencies(), true);
  }
}