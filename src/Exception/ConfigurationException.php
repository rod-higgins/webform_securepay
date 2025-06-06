<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for configuration errors.
 */
class ConfigurationException extends PaymentException {

  /**
   * Creates a configuration exception for missing client ID.
   */
  public static function missingClientId(): self {
    return new self('Client ID is required. Please configure your SecurePay OAuth credentials.');
  }

  /**
   * Creates a configuration exception for missing client secret.
   */
  public static function missingClientSecret(): self {
    return new self('Client Secret is required. Please configure your SecurePay OAuth credentials.');
  }

  /**
   * Creates a configuration exception for missing merchant code.
   */
  public static function missingMerchantCode(): self {
    return new self('Merchant Code is required. Please configure your SecurePay merchant settings.');
  }

  /**
   * Creates a configuration exception for invalid environment.
   */
  public static function invalidEnvironment(string $environment): self {
    return new self("Invalid environment '{$environment}'. Must be 'sandbox' or 'live'.");
  }

  /**
   * Creates a configuration exception for SSL requirements.
   */
  public static function sslRequired(): self {
    return new self('SSL/HTTPS is required for live environment payments.');
  }

  /**
   * Creates a configuration exception for invalid timeout values.
   */
  public static function invalidTimeout(int $timeout): self {
    return new self("Invalid timeout value '{$timeout}'. Must be between 5 and 300 seconds.");
  }

  /**
   * Creates a configuration exception for missing card types.
   */
  public static function noCardTypesConfigured(): self {
    return new self('At least one card type must be configured and enabled.');
  }

  /**
   * Creates a configuration exception for webhook configuration.
   */
  public static function invalidWebhookConfig(string $reason): self {
    return new self("Webhook configuration error: {$reason}");
  }

  /**
   * Creates a configuration exception for DCC requirements.
   */
  public static function dccRequiresOrderToken(): self {
    return new self('Dynamic Currency Conversion requires an order token from SecurePay.');
  }

  /**
   * Creates a configuration exception for 3DS2 requirements.
   */
  public static function threeDSRequiresSetup(): self {
    return new self('3D Secure 2 requires additional merchant configuration with SecurePay.');
  }
}