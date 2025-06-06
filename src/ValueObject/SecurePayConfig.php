<?php

namespace Drupal\webform_securepay\ValueObject;

use Drupal\webform_securepay\Exception\ConfigurationException;

/**
 * SecurePay configuration value object.
 */
class SecurePayConfig {

  private string $clientId;
  private string $clientSecret;
  private string $merchantCode;
  private string $environment;
  private string $currency;
  private string $orderIdPrefix;
  private int $timeout;
  private array $allowedCardTypes;
  private bool $dccEnabled;
  private bool $threeDSEnabled;
  private bool $fraudGuardEnabled;
  private bool $logTransactions;
  private bool $debugMode;

  // API endpoint configurations
  private const ENDPOINTS = [
    'live' => [
      'auth' => 'https://welcome.api2.auspost.com.au/oauth/token',
      'api' => 'https://payments.auspost.net.au',
      'ui_sdk' => 'https://payments.auspost.net.au/v3/ui/client/securepay-ui.min.js',
      'threeDS_sdk' => 'https://api.securepay.com.au/threeds-js/securepay-threeds.js',
    ],
    'sandbox' => [
      'auth' => 'https://welcome.api2.sandbox.auspost.com.au/oauth/token',
      'api' => 'https://payments-stest.npe.auspost.zone',
      'ui_sdk' => 'https://payments-stest.npe.auspost.zone/v3/ui/client/securepay-ui.min.js',
      'threeDS_sdk' => 'https://test.api.securepay.com.au/threeds-js/securepay-threeds.js',
    ],
  ];

  public function __construct(
    string $clientId,
    string $clientSecret,
    string $merchantCode,
    string $environment,
    string $currency = 'AUD',
    string $orderIdPrefix = 'WF_',
    int $timeout = 30,
    array $allowedCardTypes = ['visa', 'mastercard', 'amex', 'diners'],
    bool $dccEnabled = false,
    bool $threeDSEnabled = false,
    bool $fraudGuardEnabled = false,
    bool $logTransactions = false,
    bool $debugMode = false
  ) {
    $this->clientId = $clientId;
    $this->clientSecret = $clientSecret;
    $this->merchantCode = $merchantCode;
    $this->environment = $environment;
    $this->currency = $currency;
    $this->orderIdPrefix = $orderIdPrefix;
    $this->timeout = $timeout;
    $this->allowedCardTypes = $allowedCardTypes;
    $this->dccEnabled = $dccEnabled;
    $this->threeDSEnabled = $threeDSEnabled;
    $this->fraudGuardEnabled = $fraudGuardEnabled;
    $this->logTransactions = $logTransactions;
    $this->debugMode = $debugMode;

    $this->validateConfiguration();
  }

  public function getClientId(): string {
    return $this->clientId;
  }

  public function getClientSecret(): string {
    return $this->clientSecret;
  }

  public function getMerchantCode(): string {
    return $this->merchantCode;
  }

  public function getEnvironment(): string {
    return $this->environment;
  }

  public function getCurrency(): string {
    return $this->currency;
  }

  public function getOrderIdPrefix(): string {
    return $this->orderIdPrefix;
  }

  public function getTimeout(): int {
    return $this->timeout;
  }

  public function getAllowedCardTypes(): array {
    return $this->allowedCardTypes;
  }

  public function isDccEnabled(): bool {
    return $this->dccEnabled;
  }

  public function isThreeDSEnabled(): bool {
    return $this->threeDSEnabled;
  }

  public function isFraudGuardEnabled(): bool {
    return $this->fraudGuardEnabled;
  }

  public function isLogTransactions(): bool {
    return $this->logTransactions;
  }

  public function isDebugMode(): bool {
    return $this->debugMode;
  }
  
  /**
   * Validate configuration values.
   */
  private function validateConfiguration(): void {
    if (empty(trim($this->clientId))) {
      throw ConfigurationException::missingClientId();
    }
    
    if (empty(trim($this->clientSecret))) {
      throw ConfigurationException::missingClientSecret();
    }
    
    if (empty(trim($this->merchantCode))) {
      throw ConfigurationException::missingMerchantCode();
    }
    
    if (!in_array($this->environment, ['sandbox', 'live'], true)) {
      throw ConfigurationException::invalidEnvironment($this->environment);
    }

    if ($this->timeout < 5 || $this->timeout > 300) {
      throw ConfigurationException::invalidTimeout($this->timeout);
    }

    if (empty($this->allowedCardTypes)) {
      throw ConfigurationException::noCardTypesConfigured();
    }

    // Check SSL requirement for live environment
    if ($this->isLive() && !$this->isSSLEnabled()) {
      throw ConfigurationException::sslRequired();
    }
  }

  /**
   * Check if running in live environment.
   */
  public function isLive(): bool {
    return $this->environment === 'live';
  }

  /**
   * Check if running in sandbox environment.
   */
  public function isSandbox(): bool {
    return $this->environment === 'sandbox';
  }

  /**
   * Get API endpoints for current environment.
   */
  public function getApiEndpoints(): array {
    return self::ENDPOINTS[$this->environment] ?? self::ENDPOINTS['sandbox'];
  }

  /**
   * Get authentication endpoint.
   */
  public function getAuthEndpoint(): string {
    return $this->getApiEndpoints()['auth'];
  }

  /**
   * Get API base URL.
   */
  public function getApiBaseUrl(): string {
    return $this->getApiEndpoints()['api'];
  }

  /**
   * Get UI SDK URL.
   */
  public function getUiSdkUrl(): string {
    return $this->getApiEndpoints()['ui_sdk'];
  }

  /**
   * Get 3DS2 SDK URL.
   */
  public function getThreeDS2SdkUrl(): string {
    return $this->getApiEndpoints()['threeDS_sdk'];
  }

  /**
   * Convert to array for JavaScript settings.
   */
  public function toJavaScriptSettings(): array {
    return [
      'clientId' => $this->clientId,
      'merchantCode' => $this->merchantCode,
      'environment' => $this->environment,
      'currency' => $this->currency,
      'allowedCardTypes' => array_values($this->allowedCardTypes),
      'dccEnabled' => $this->dccEnabled,
      'threeDSEnabled' => $this->threeDSEnabled,
      'fraudGuardEnabled' => $this->fraudGuardEnabled,
      'endpoints' => $this->getApiEndpoints(),
      'isLive' => $this->isLive(),
      'debugMode' => $this->debugMode && !$this->isLive(), // Never debug in live
    ];
  }

  /**
   * Get configuration summary for admin display.
   */
  public function getSummary(): array {
    return [
      'environment' => $this->environment,
      'currency' => $this->currency,
      'merchant_code' => $this->merchantCode,
      'client_id' => substr($this->clientId, 0, 8) . '...',
      'features' => [
        'dcc' => $this->dccEnabled,
        'three_ds' => $this->threeDSEnabled,
        'fraud_guard' => $this->fraudGuardEnabled,
      ],
      'card_types' => $this->allowedCardTypes,
      'timeout' => $this->timeout,
    ];
  }

  /**
   * Check if the configuration is complete.
   */
  public function isComplete(): bool {
    return !empty($this->clientId) && 
           !empty($this->clientSecret) && 
           !empty($this->merchantCode);
  }

  /**
   * Check if SSL is enabled (for live environment requirement).
   */
  private function isSSLEnabled(): bool {
    return !empty($_SERVER['HTTPS']) || 
           ($_SERVER['SERVER_PORT'] ?? 80) == 443 ||
           (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  }

  /**
   * Get OAuth 2.0 credentials for API authentication.
   */
  public function getOAuthCredentials(): array {
    return [
      'client_id' => $this->clientId,
      'client_secret' => $this->clientSecret,
      'grant_type' => 'client_credentials',
      'audience' => 'https://api.payments.auspost.com.au',
    ];
  }

  /**
   * Get request headers for API calls.
   */
  public function getApiHeaders(string $accessToken): array {
    return [
      'Authorization' => "Bearer {$accessToken}",
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
      'User-Agent' => 'Drupal-WebformSecurePay/2.0',
    ];
  }
}