<?php

namespace Drupal\webform_securepay\ValueObject;

/**
 * Payment result value object.
 */
class PaymentResult {

  // Status constants
  public const STATUS_COMPLETED = 'completed';
  public const STATUS_PENDING = 'pending';
  public const STATUS_FAILED = 'failed';
  public const STATUS_CANCELLED = 'cancelled';
  public const STATUS_REFUNDED = 'refunded';

  // Validation constants
  private const MAX_TRANSACTION_ID_LENGTH = 255;
  private const MAX_ERROR_MESSAGE_LENGTH = 1000;

  public function __construct(
    private readonly bool $success,
    private readonly ?string $transactionId = null,
    private readonly ?string $status = null,
    private readonly ?string $error = null,
    private readonly array $rawResponse = [],
  ) {
    $this->validate();
  }

  /**
   * Check if payment was successful.
   */
  public function isSuccess(): bool {
    return $this->success;
  }

  /**
   * Get the transaction ID.
   */
  public function getTransactionId(): ?string {
    return $this->transactionId;
  }

  /**
   * Get the payment status.
   */
  public function getStatus(): ?string {
    return $this->status;
  }

  /**
   * Get the error message.
   */
  public function getError(): ?string {
    return $this->error;
  }

  /**
   * Get the raw API response.
   */
  public function getRawResponse(): array {
    return $this->rawResponse;
  }

  /**
   * Check if the payment is pending.
   */
  public function isPending(): bool {
    return $this->status === self::STATUS_PENDING;
  }

  /**
   * Check if the payment failed.
   */
  public function isFailed(): bool {
    return !$this->success || $this->status === self::STATUS_FAILED;
  }

  /**
   * Check if the payment was cancelled.
   */
  public function isCancelled(): bool {
    return $this->status === self::STATUS_CANCELLED;
  }

  /**
   * Check if the payment was refunded.
   */
  public function isRefunded(): bool {
    return $this->status === self::STATUS_REFUNDED;
  }

  /**
   * Get user-friendly status message.
   */
  public function getStatusMessage(): string {
    if (!$this->success) {
      return $this->error ?? 'Payment failed';
    }

    return match ($this->status) {
      self::STATUS_COMPLETED => 'Payment completed successfully',
      self::STATUS_PENDING => 'Payment is being processed',
      self::STATUS_FAILED => 'Payment failed',
      self::STATUS_CANCELLED => 'Payment was cancelled',
      self::STATUS_REFUNDED => 'Payment was refunded',
      default => 'Payment processed',
    };
  }

  /**
   * Create success result.
   */
  public static function success(string $transactionId, string $status = self::STATUS_COMPLETED, array $rawResponse = []): self {
    return new self(
      success: true,
      transactionId: self::sanitizeTransactionId($transactionId),
      status: self::sanitizeStatus($status),
      rawResponse: self::sanitizeRawResponse($rawResponse),
    );
  }

  /**
   * Create failure result.
   */
  public static function failure(string $error, array $rawResponse = []): self {
    return new self(
      success: false,
      error: self::sanitizeErrorMessage($error),
      rawResponse: self::sanitizeRawResponse($rawResponse),
    );
  }

  /**
   * Create pending result.
   */
  public static function pending(string $transactionId, array $rawResponse = []): self {
    return new self(
      success: true,
      transactionId: self::sanitizeTransactionId($transactionId),
      status: self::STATUS_PENDING,
      rawResponse: self::sanitizeRawResponse($rawResponse),
    );
  }

  /**
   * Convert to array for API responses.
   */
  public function toArray(): array {
    return [
      'success' => $this->success,
      'transaction_id' => $this->transactionId,
      'status' => $this->status,
      'error' => $this->error,
      'message' => $this->getStatusMessage(),
    ];
  }

  /**
   * Convert to detailed array for logging.
   */
  public function toDetailedArray(): array {
    return [
      'success' => $this->success,
      'transaction_id' => $this->transactionId,
      'status' => $this->status,
      'error' => $this->error,
      'message' => $this->getStatusMessage(),
      'raw_response' => $this->rawResponse,
      'timestamp' => time(),
    ];
  }

  /**
   * Create from API response array.
   */
  public static function fromApiResponse(array $response): self {
    $success = !empty($response['success']) || !empty($response['transactionId']);
    
    if ($success) {
      return self::success(
        $response['transactionId'] ?? '',
        $response['status'] ?? self::STATUS_COMPLETED,
        $response
      );
    }

    return self::failure(
      $response['error'] ?? $response['message'] ?? 'Payment failed',
      $response
    );
  }

  /**
   * Validate result data.
   */
  private function validate(): void {
    // If successful, must have transaction ID
    if ($this->success && empty($this->transactionId)) {
      throw new \InvalidArgumentException('Successful payment must have transaction ID');
    }

    // If failed, should have error message
    if (!$this->success && empty($this->error)) {
      throw new \InvalidArgumentException('Failed payment should have error message');
    }

    // Validate transaction ID length
    if ($this->transactionId !== null && strlen($this->transactionId) > self::MAX_TRANSACTION_ID_LENGTH) {
      throw new \InvalidArgumentException('Transaction ID too long');
    }

    // Validate error message length
    if ($this->error !== null && strlen($this->error) > self::MAX_ERROR_MESSAGE_LENGTH) {
      throw new \InvalidArgumentException('Error message too long');
    }
  }

  /**
   * Sanitize transaction ID.
   */
  private static function sanitizeTransactionId(string $transactionId): string {
    $sanitized = trim($transactionId);
    if (empty($sanitized)) {
      throw new \InvalidArgumentException('Transaction ID cannot be empty');
    }

    // Remove any potentially harmful characters
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $sanitized);
    
    return substr($sanitized, 0, self::MAX_TRANSACTION_ID_LENGTH);
  }

  /**
   * Sanitize status.
   */
  private static function sanitizeStatus(string $status): string {
    $sanitized = trim(strtolower($status));
    
    // Map common status variations
    $statusMap = [
      'success' => self::STATUS_COMPLETED,
      'complete' => self::STATUS_COMPLETED,
      'approved' => self::STATUS_COMPLETED,
      'processing' => self::STATUS_PENDING,
      'error' => self::STATUS_FAILED,
      'declined' => self::STATUS_FAILED,
      'cancel' => self::STATUS_CANCELLED,
      'void' => self::STATUS_CANCELLED,
      'refund' => self::STATUS_REFUNDED,
    ];

    return $statusMap[$sanitized] ?? $sanitized;
  }

  /**
   * Sanitize error message.
   */
  private static function sanitizeErrorMessage(string $error): string {
    $sanitized = trim($error);
    if (empty($sanitized)) {
      return 'Unknown error occurred';
    }

    // Truncate if too long
    if (strlen($sanitized) > self::MAX_ERROR_MESSAGE_LENGTH) {
      $sanitized = substr($sanitized, 0, self::MAX_ERROR_MESSAGE_LENGTH - 3) . '...';
    }

    return $sanitized;
  }

  /**
   * Sanitize raw response data.
   */
  private static function sanitizeRawResponse(array $rawResponse): array {
    // Remove sensitive data that should never be stored
    $sensitiveFields = [
      'cardNumber', 'cvv', 'expiryDate', 'cardholderName',
      'token', 'clientSecret', 'password', 'pin', 'authCode'
    ];

    $sanitized = $rawResponse;
    
    foreach ($sensitiveFields as $field) {
      unset($sanitized[$field]);
    }

    // Recursively sanitize nested arrays
    array_walk_recursive($sanitized, function (&$value, $key) use ($sensitiveFields) {
      if (in_array($key, $sensitiveFields, true)) {
        $value = '[REDACTED]';
      }
      
      // Truncate very long strings to prevent memory issues
      if (is_string($value) && strlen($value) > 10000) {
        $value = substr($value, 0, 10000) . '... [TRUNCATED]';
      }
    });

    return $sanitized;
  }

  /**
   * Create a copy with different status.
   */
  public function withStatus(string $status): self {
    return new self(
      $this->success,
      $this->transactionId,
      $status,
      $this->error,
      $this->rawResponse
    );
  }

  /**
   * Create a copy with additional response data.
   */
  public function withAdditionalData(array $additionalData): self {
    return new self(
      $this->success,
      $this->transactionId,
      $this->status,
      $this->error,
      array_merge($this->rawResponse, self::sanitizeRawResponse($additionalData))
    );
  }
}