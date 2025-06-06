<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Base exception for payment processing errors.
 */
class PaymentException extends \Exception {

  /**
   * Creates a payment exception for invalid amounts.
   */
  public static function invalidAmount(int $amount): self {
    return new self("Invalid payment amount: {$amount} cents");
  }

  /**
   * Creates a payment exception for missing tokens.
   */
  public static function missingToken(): self {
    return new self('Payment token is required');
  }

  /**
   * Creates a payment exception for API failures.
   */
  public static function apiFailure(string $message, int $code = 0, ?\Throwable $previous = null): self {
    return new self("API error: {$message}", $code, $previous);
  }

  /**
   * Creates a payment exception for rate limiting.
   */
  public static function rateLimitExceeded(): self {
    return new self('Rate limit exceeded. Please try again later.');
  }

  /**
   * Creates a payment exception for configuration issues.
   */
  public static function configurationError(string $field): self {
    return new self("Configuration error: {$field} is required");
  }
}