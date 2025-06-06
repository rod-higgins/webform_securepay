<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Configuration service for SecurePay settings.
 */
class ConfigurationService {

  public const CONFIG_NAME = 'webform_securepay.settings';

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
    $required = ['client_id', 'client_secret', 'merchant_code'];
    
    foreach ($required as $field) {
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
    $isLive = $this->get('environment') === 'live';
    $baseUrl = $isLive 
      ? 'https://api.payments.auspost.com.au'
      : 'https://api.payments.test.auspost.com.au';

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
      'sandbox' => t('Sandbox (Testing)'),
      'live' => t('Live (Production)'),
    ];
  }

  /**
   * Get currency options for forms.
   */
  public static function getCurrencyOptions(): array {
    return [
      'AUD' => t('Australian Dollar'),
      'USD' => t('US Dollar'),
      'EUR' => t('Euro'),
      'GBP' => t('British Pound'),
    ];
  }
}