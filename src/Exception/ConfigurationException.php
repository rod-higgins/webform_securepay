<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for configuration errors.
 */
class ConfigurationException extends PaymentException {

  // Configuration error codes
  public const MISSING_CONFIGURATION = 'MISSING_CONFIGURATION';
  public const MISSING_CLIENT_ID = 'MISSING_CLIENT_ID';
  public const MISSING_CLIENT_SECRET = 'MISSING_CLIENT_SECRET';
  public const MISSING_MERCHANT_CODE = 'MISSING_MERCHANT_CODE';
  public const INVALID_ENVIRONMENT = 'INVALID_ENVIRONMENT';
  public const SSL_REQUIRED = 'SSL_REQUIRED';
  public const INVALID_TIMEOUT = 'INVALID_TIMEOUT';
  public const NO_CARD_TYPES = 'NO_CARD_TYPES';
  public const INVALID_WEBHOOK_CONFIG = 'INVALID_WEBHOOK_CONFIG';
  public const DCC_REQUIRES_ORDER_TOKEN = 'DCC_REQUIRES_ORDER_TOKEN';
  public const THREEDS_REQUIRES_SETUP = 'THREEDS_REQUIRES_SETUP';
  public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
  public const PERMISSION_DENIED = 'PERMISSION_DENIED';

  /**
   * Creates a configuration exception for missing configuration.
   */
  public static function missingConfiguration(): self {
    return new self(
      'SecurePay is not properly configured. Please check Client ID, Client Secret, and Merchant Code.',
      0,
      null,
      self::MISSING_CONFIGURATION
    );
  }

  /**
   * Creates a configuration exception for missing client ID.
   */
  public static function missingClientId(): self {
    return new self(
      'Client ID is required. Please configure your SecurePay OAuth credentials.',
      0,
      null,
      self::MISSING_CLIENT_ID
    );
  }

  /**
   * Creates a configuration exception for missing client secret.
   */
  public static function missingClientSecret(): self {
    return new self(
      'Client Secret is required. Please configure your SecurePay OAuth credentials.',
      0,
      null,
      self::MISSING_CLIENT_SECRET
    );
  }

  /**
   * Creates a configuration exception for missing merchant code.
   */
  public static function missingMerchantCode(): self {
    return new self(
      'Merchant Code is required. Please configure your SecurePay merchant settings.',
      0,
      null,
      self::MISSING_MERCHANT_CODE
    );
  }

  /**
   * Creates a configuration exception for invalid environment.
   */
  public static function invalidEnvironment(string $environment): self {
    return new self(
      "Invalid environment '{$environment}'. Must be 'sandbox' or 'live'.",
      0,
      null,
      self::INVALID_ENVIRONMENT,
      ['environment' => $environment]
    );
  }

  /**
   * Creates a configuration exception for SSL requirements.
   */
  public static function sslRequired(): self {
    return new self(
      'SSL/HTTPS is required for live environment payments.',
      0,
      null,
      self::SSL_REQUIRED
    );
  }

  /**
   * Creates a configuration exception for invalid timeout values.
   */
  public static function invalidTimeout(int $timeout, int $min = 5, int $max = 300): self {
    return new self(
      "Invalid timeout value '{$timeout}'. Must be between {$min} and {$max} seconds.",
      0,
      null,
      self::INVALID_TIMEOUT,
      ['timeout' => $timeout, 'min' => $min, 'max' => $max]
    );
  }

  /**
   * Creates a configuration exception for missing card types.
   */
  public static function noCardTypesConfigured(): self {
    return new self(
      'At least one card type must be configured and enabled.',
      0,
      null,
      self::NO_CARD_TYPES
    );
  }

  /**
   * Creates a configuration exception for webhook configuration.
   */
  public static function invalidWebhookConfig(string $reason): self {
    return new self(
      "Webhook configuration error: {$reason}",
      0,
      null,
      self::INVALID_WEBHOOK_CONFIG,
      ['reason' => $reason]
    );
  }

  /**
   * Creates a configuration exception for DCC requirements.
   */
  public static function dccRequiresOrderToken(): self {
    return new self(
      'Dynamic Currency Conversion requires an order token from SecurePay.',
      0,
      null,
      self::DCC_REQUIRES_ORDER_TOKEN
    );
  }

  /**
   * Creates a configuration exception for 3DS2 requirements.
   */
  public static function threeDSRequiresSetup(): self {
    return new self(
      '3D Secure 2 requires additional merchant configuration with SecurePay.',
      0,
      null,
      self::THREEDS_REQUIRES_SETUP
    );
  }

  /**
   * Creates a configuration exception for invalid credentials format.
   */
  public static function invalidCredentials(string $field, string $reason): self {
    return new self(
      "Invalid {$field} format: {$reason}",
      0,
      null,
      self::INVALID_CREDENTIALS,
      ['field' => $field, 'reason' => $reason]
    );
  }

  /**
   * Creates a configuration exception for permission issues.
   */
  public static function permissionDenied(string $operation): self {
    return new self(
      "Permission denied for operation: {$operation}. Please check your account permissions.",
      0,
      null,
      self::PERMISSION_DENIED,
      ['operation' => $operation]
    );
  }

  /**
   * Creates a configuration exception for currency configuration.
   */
  public static function invalidCurrencyConfig(string $currency, array $supportedCurrencies): self {
    $supported = implode(', ', $supportedCurrencies);
    return new self(
      "Currency '{$currency}' is not supported by your merchant account. Supported: {$supported}",
      0,
      null,
      self::INVALID_ENVIRONMENT,
      ['currency' => $currency, 'supported' => $supportedCurrencies]
    );
  }

  /**
   * Creates a configuration exception for feature availability.
   */
  public static function featureNotAvailable(string $feature, string $reason): self {
    return new self(
      "Feature '{$feature}' is not available: {$reason}",
      0,
      null,
      self::INVALID_CONFIGURATION,
      ['feature' => $feature, 'reason' => $reason]
    );
  }

  /**
   * Creates a configuration exception for API version compatibility.
   */
  public static function incompatibleApiVersion(string $currentVersion, string $requiredVersion): self {
    return new self(
      "API version '{$currentVersion}' is not compatible. Required version: {$requiredVersion}",
      0,
      null,
      self::INVALID_CONFIGURATION,
      ['current_version' => $currentVersion, 'required_version' => $requiredVersion]
    );
  }

  /**
   * Creates a configuration exception for missing dependencies.
   */
  public static function missingDependency(string $dependency, string $purpose): self {
    return new self(
      "Missing required dependency '{$dependency}' for {$purpose}",
      0,
      null,
      self::MISSING_CONFIGURATION,
      ['dependency' => $dependency, 'purpose' => $purpose]
    );
  }

  /**
   * Creates a configuration exception for invalid URL format.
   */
  public static function invalidUrl(string $urlType, string $url): self {
    return new self(
      "Invalid {$urlType} URL format: {$url}",
      0,
      null,
      self::INVALID_CONFIGURATION,
      ['url_type' => $urlType, 'url' => $url]
    );
  }

  /**
   * Creates a configuration exception for file system issues.
   */
  public static function fileSystemError(string $operation, string $path, string $reason): self {
    return new self(
      "File system error during {$operation} at '{$path}': {$reason}",
      0,
      null,
      self::INVALID_CONFIGURATION,
      ['operation' => $operation, 'path' => $path, 'reason' => $reason]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMessage(): string {
    // Configuration errors should show generic message to users
    return 'Payment system configuration error. Please contact the site administrator.';
  }

  /**
   * {@inheritdoc}
   */
  public function isRetryable(): bool {
    // Configuration errors are not retryable - they need admin intervention
    return false;
  }
}