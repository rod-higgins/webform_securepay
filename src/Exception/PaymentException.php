<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for payment-related errors.
 */
class PaymentException extends \Exception {

  // Error code constants
  public const ERROR_CONFIGURATION = 'CONFIGURATION_ERROR';
  public const ERROR_API = 'API_ERROR';
  public const ERROR_VALIDATION = 'VALIDATION_ERROR';
  public const ERROR_NETWORK = 'NETWORK_ERROR';
  public const ERROR_AUTHENTICATION = 'AUTHENTICATION_ERROR';
  public const ERROR_PROCESSING = 'PROCESSING_ERROR';

  public function __construct(
    string $message = "",
    int $code = 0,
    ?\Throwable $previous = null,
    private readonly ?string $errorCode = null,
    private readonly array $context = []
  ) {
    parent::__construct($message, $code, $previous);
  }

  /**
   * Get the error code.
   */
  public function getErrorCode(): ?string {
    return $this->errorCode;
  }

  /**
   * Get the error context.
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Get user-friendly error message.
   */
  public function getUserMessage(): string {
    // Return a sanitized message safe for display to users
    switch ($this->errorCode) {
      case self::ERROR_CONFIGURATION:
        return 'Payment system configuration error. Please contact the site administrator.';
        
      case self::ERROR_API:
        return 'Payment service temporarily unavailable. Please try again later.';
        
      case self::ERROR_VALIDATION:
        return $this->message; // Validation messages are usually safe to display
        
      case self::ERROR_NETWORK:
        return 'Network connection error. Please check your internet connection and try again.';
        
      case self::ERROR_AUTHENTICATION:
        return 'Payment authentication failed. Please contact the site administrator.';
        
      case self::ERROR_PROCESSING:
        return 'Payment processing failed. Please try again or contact support.';
        
      default:
        return 'An unexpected error occurred. Please try again later.';
    }
  }

  /**
   * Check if error is retryable.
   */
  public function isRetryable(): bool {
    return in_array($this->errorCode, [
      self::ERROR_NETWORK,
      self::ERROR_API,
    ], true);
  }

  /**
   * Create a generic payment error.
   */
  public static function create(string $message, int $code = 0, array $context = []): self {
    return new self($message, $code, null, self::ERROR_PROCESSING, $context);
  }

  /**
   * Create a configuration error.
   */
  public static function configuration(string $message, array $context = []): self {
    return new self("Configuration error: {$message}", 0, null, self::ERROR_CONFIGURATION, $context);
  }

  /**
   * Create an API error.
   */
  public static function api(string $message, int $code = 0, array $context = []): self {
    return new self("API error: {$message}", $code, null, self::ERROR_API, $context);
  }

  /**
   * Create a validation error.
   */
  public static function validation(string $message, array $context = []): self {
    return new self("Validation error: {$message}", 0, null, self::ERROR_VALIDATION, $context);
  }

  /**
   * Create a network error.
   */
  public static function network(string $message, array $context = []): self {
    return new self("Network error: {$message}", 0, null, self::ERROR_NETWORK, $context);
  }

  /**
   * Create an authentication error.
   */
  public static function authentication(string $message, array $context = []): self {
    return new self("Authentication error: {$message}", 401, null, self::ERROR_AUTHENTICATION, $context);
  }

  /**
   * Create a processing error.
   */
  public static function processing(string $message, array $context = []): self {
    return new self("Processing error: {$message}", 0, null, self::ERROR_PROCESSING, $context);
  }

  /**
   * Convert to array for logging.
   */
  public function toArray(): array {
    return [
      'message' => $this->getMessage(),
      'code' => $this->getCode(),
      'error_code' => $this->errorCode,
      'context' => $this->context,
      'file' => $this->getFile(),
      'line' => $this->getLine(),
      'trace' => $this->getTraceAsString(),
    ];
  }

  /**
   * Create from another exception.
   */
  public static function fromException(\Throwable $exception, ?string $errorCode = null, array $context = []): self {
    return new self(
      $exception->getMessage(),
      $exception->getCode(),
      $exception,
      $errorCode ?? self::ERROR_PROCESSING,
      $context
    );
  }
}