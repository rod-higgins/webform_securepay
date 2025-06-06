<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for payment-related errors.
 */
class PaymentException extends \Exception {

  /**
   * Create a generic payment error.
   */
  public static function create(string $message, int $code = 0): self {
    return new self($message, $code);
  }

  /**
   * Create a configuration error.
   */
  public static function configuration(string $message): self {
    return new self("Configuration error: {$message}");
  }

  /**
   * Create an API error.
   */
  public static function api(string $message, int $code = 0): self {
    return new self("API error: {$message}", $code);
  }

  /**
   * Create a validation error.
   */
  public static function validation(string $message): self {
    return new self("Validation error: {$message}");
  }
}