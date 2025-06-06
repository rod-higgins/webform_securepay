<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Base exception class for payment-related errors.
 */
class PaymentException extends \Exception {

  private ?string $errorCode;
  private ?array $context;

  public function __construct(
    string $message = '',
    int $code = 0,
    ?\Throwable $previous = null,
    ?string $errorCode = null,
    ?array $context = null
  ) {
    parent::__construct($message, $code, $previous);
    $this->errorCode = $errorCode;
    $this->context = $context ?? [];
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
   * Check if this is a user-facing error.
   */
  public function isUserFacing(): bool {
    return in_array(get_class($this), [
      ValidationException::class,
      ConfigurationException::class,
    ]);
  }

  /**
   * Get user-friendly error message.
   */
  public function getUserMessage(): string {
    return $this->isUserFacing() 
      ? $this->getMessage()
      : 'An error occurred while processing your payment. Please try again.';
  }

  /**
   * Convert to array for API responses.
   */
  public function toArray(): array {
    return [
      'error' => true,
      'error_code' => $this->errorCode,
      'message' => $this->getUserMessage(),
      'context' => $this->context,
      'timestamp' => time(),
    ];
  }

  /**
   * Create a generic payment error.
   */
  public static function generic(string $message, ?string $errorCode = null): self {
    return new self($message, 0, null, $errorCode);
  }

  /**
   * Create a payment processing error.
   */
  public static function processingFailed(string $reason, ?array $context = null): self {
    return new self(
      "Payment processing failed: {$reason}",
      0,
      null,
      'PROCESSING_FAILED',
      $context
    );
  }

  /**
   * Create a network/connectivity error.
   */
  public static function networkError(string $details): self {
    return new self(
      "Network error occurred: {$details}",
      0,
      null,
      'NETWORK_ERROR'
    );
  }

  /**
   * Create a timeout error.
   */
  public static function timeout(int $timeoutSeconds): self {
    return new self(
      "Payment request timed out after {$timeoutSeconds} seconds",
      0,
      null,
      'TIMEOUT'
    );
  }

  /**
   * Create an insufficient funds error.
   */
  public static function insufficientFunds(): self {
    return new self(
      'Insufficient funds available for this transaction',
      0,
      null,
      'INSUFFICIENT_FUNDS'
    );
  }

  /**
   * Create a card declined error.
   */
  public static function cardDeclined(string $reason = 'Unknown'): self {
    return new self(
      "Payment card was declined: {$reason}",
      0,
      null,
      'CARD_DECLINED'
    );
  }

  /**
   * Create a fraud detection error.
   */
  public static function fraudDetected(string $reason): self {
    return new self(
      "Transaction blocked by fraud detection: {$reason}",
      0,
      null,
      'FRAUD_DETECTED'
    );
  }

  /**
   * Create a system maintenance error.
   */
  public static function systemMaintenance(): self {
    return new self(
      'Payment system is currently under maintenance. Please try again later.',
      0,
      null,
      'SYSTEM_MAINTENANCE'
    );
  }
}