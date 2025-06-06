<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for payment-related errors.
 */
class PaymentException extends \Exception {

  public function __construct(
    string $message = "",
    int $code = 0,
    ?\Throwable $previous = null,
    private readonly ?string $errorCode = null,
    private readonly array $context = []
  ) {
    parent::__construct($message, $code, $previous);
  }

  public function getErrorCode(): ?string {
    return $this->errorCode;
  }

  public function getContext(): array {
    return $this->context;
  }

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
    return new self("Configuration error: {$message}", 0, null, 'CONFIGURATION_ERROR');
  }

  /**
   * Create an API error.
   */
  public static function api(string $message, int $code = 0): self {
    return new self("API error: {$message}", $code, null, 'API_ERROR');
  }

  /**
   * Create a validation error.
   */
  public static function validation(string $message): self {
    return new self("Validation error: {$message}", 0, null, 'VALIDATION_ERROR');
  }
}