<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;

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
    private readonly ?KeyRepositoryInterface $keyRepository = null,
    private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
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
   * Get credential value, using Key module if configured.
   */
  public function getCredential(string $credentialName): ?string {
    $useKeyModule = $this->get('use_key_module', false);
    
    if ($useKeyModule && $this->isKeyModuleAvailable()) {
      $keyName = $this->get($credentialName . '_key');
      if (!empty($keyName)) {
        return $this->getValueFromKey($keyName);
      }
    }
    
    // Fallback to direct configuration storage
    return $this->get($credentialName);
  }

  /**
   * Get client ID from configuration or key.
   */
  public function getClientId(): ?string {
    return $this->getCredential('client_id');
  }

  /**
   * Get client secret from configuration or key.
   */
  public function getClientSecret(): ?string {
    return $this->getCredential('client_secret');
  }

  /**
   * Get merchant code from configuration or key.
   */
  public function getMerchantCode(): ?string {
    return $this->getCredential('merchant_code');
  }

  /**
   * Check if SecurePay is configured.
   */
  public function isConfigured(): bool {
    $clientId = $this->getClientId();
    $clientSecret = $this->getClientSecret();
    $merchantCode = $this->getMerchantCode();
    
    return !empty($clientId) && !empty($clientSecret) && !empty($merchantCode);
  }

  /**
   * Check if Key module is available and enabled.
   */
  public function isKeyModuleAvailable(): bool {
    return $this->keyRepository !== null && 
           $this->entityTypeManager !== null && 
           $this->entityTypeManager->hasDefinition('key');
  }

  /**
   * Get value from a key entity.
   */
  private function getValueFromKey(string $keyId): ?string {
    if (!$this->isKeyModuleAvailable()) {
      return null;
    }

    try {
      $key = $this->keyRepository->getKey($keyId);
      if ($key) {
        return $key->getKeyValue();
      }
    } catch (\Exception $e) {
      \Drupal::logger('webform_securepay')->error('Failed to retrieve key @key_id: @message', [
        '@key_id' => $keyId,
        '@message' => $e->getMessage(),
      ]);
    }

    return null;
  }

  /**
   * Get available keys for credential storage.
   */
  public function getAvailableKeys(): array {
    if (!$this->isKeyModuleAvailable()) {
      return [];
    }

    try {
      $keys = $this->entityTypeManager->getStorage('key')->loadMultiple();
      $options = ['_none' => '- Select a key -'];
      
      foreach ($keys as $key) {
        $options[$key->id()] = $key->label();
      }
      
      return $options;
    } catch (\Exception $e) {
      \Drupal::logger('webform_securepay')->error('Failed to load available keys: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Check if using Key module for credential storage.
   */
  public function isUsingKeyModule(): bool {
    return $this->get('use_key_module', false) && $this->isKeyModuleAvailable();
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
  public static function isValidCurrency(string $currency): bool {
    return in_array($currency, self::getValidCurrencies(), true);
  }
}