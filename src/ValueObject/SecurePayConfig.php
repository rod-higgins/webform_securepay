<?php

namespace Drupal\webform_securepay\ValueObject;

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

  public function __construct(
    string $clientId,
    string $clientSecret,
    string $merchantCode,
    string $environment,
    string $currency,
    string $orderIdPrefix,
    int $timeout,
    array $allowedCardTypes,
    bool $dccEnabled,
    bool $threeDSEnabled,
    bool $fraudGuardEnabled,
    bool $logTransactions,
    bool $debugMode
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
  }

  /**
   * Get client ID.
   */
  public function getClientId(): string {
    return $this->clientId;
  }

  /**
   * Get client secret.
   */
  public function getClientSecret(): string {
    return $this->clientSecret;
  }

  /**
   * Get merchant code.
   */
  public function getMerchantCode(): string {
    return $this->merchantCode;
  }

  /**
   * Get environment.
   */
  public function getEnvironment(): string {
    return $this->environment;
  }

  /**
   * Get currency.
   */
  public function getCurrency(): string {
    return $this->currency;
  }

  /**
   * Get order ID prefix.
   */
  public function getOrderIdPrefix(): string {
    return $this->orderIdPrefix;
  }

  /**
   * Get timeout.
   */
  public function getTimeout(): int {
    return $this->timeout;
  }

  /**
   * Get allowed card types.
   */
  public function getAllowedCardTypes(): array {
    return $this->allowedCardTypes;
  }

  /**
   * Check if DCC is enabled.
   */
  public function isDccEnabled(): bool {
    return $this->dccEnabled;
  }

  /**
   * Check if 3DS is enabled.
   */
  public function isThreeDSEnabled(): bool {
    return $this->threeDSEnabled;
  }

  /**
   * Check if FraudGuard is enabled.
   */
  public function isFraudGuardEnabled(): bool {
    return $this->fraudGuardEnabled;
  }

  /**
   * Check if transaction logging is enabled.
   */
  public function shouldLogTransactions(): bool {
    return $this->logTransactions;
  }

  /**
   * Check if debug mode is enabled.
   */
  public function isDebugMode(): bool {
    return $this->debugMode;
  }

  /**
   * Check if environment is live.
   */
  public function isLiveEnvironment(): bool {
    return $this->environment === 'live';
  }

  /**
   * Get API endpoints based on environment.
   */
  public function getApiEndpoints(): array {
    $baseUrl = $this->isLiveEnvironment() 
      ? 'https://api.payments.auspost.com.au'
      : 'https://api.payments.test.auspost.com.au';

    return [
      'auth' => $baseUrl . '/oauth/token',
      'api' => $baseUrl,
      'ui_sdk' => $baseUrl . '/ui/v2/dist/index.js',
      'threeDS_sdk' => $baseUrl . '/3ds2/v1/dist/index.js',
    ];
  }

  /**
   * Convert to JavaScript settings for frontend.
   */
  public function toJavaScriptSettings(): array {
    $endpoints = $this->getApiEndpoints();
    
    return [
      'clientId' => $this->clientId,
      'merchantCode' => $this->merchantCode,
      'environment' => $this->environment,
      'currency' => $this->currency,
      'allowedCardTypes' => $this->allowedCardTypes,
      'dccEnabled' => $this->dccEnabled,
      'threeDSEnabled' => $this->threeDSEnabled,
      'fraudGuardEnabled' => $this->fraudGuardEnabled,
      'debugMode' => $this->debugMode,
      'endpoints' => $endpoints,
    ];
  }

  /**
   * Generate unique order ID.
   */
  public function generateOrderId(?string $suffix = null): string {
    $timestamp = time();
    $random = substr(md5(uniqid()), 0, 8);
    $parts = [$this->orderIdPrefix, $timestamp, $random];
    
    if ($suffix) {
      $parts[] = $suffix;
    }
    
    return implode('_', array_filter($parts));
  }

  /**
   * Validate card type is allowed.
   */
  public function isCardTypeAllowed(string $cardType): bool {
    return in_array($cardType, $this->allowedCardTypes, true);
  }
}