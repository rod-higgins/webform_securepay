<?php

namespace Drupal\webform_securepay\ValueObject;

/**
 * Payment result value object.
 */
class PaymentResult {

  public function __construct(
    private readonly bool $success,
    private readonly ?string $transactionId = null,
    private readonly ?string $status = null,
    private readonly ?string $error = null,
    private readonly array $rawResponse = [],
  ) {}

  public function isSuccess(): bool {
    return $this->success;
  }

  public function getTransactionId(): ?string {
    return $this->transactionId;
  }

  public function getStatus(): ?string {
    return $this->status;
  }

  public function getError(): ?string {
    return $this->error;
  }

  public function getRawResponse(): array {
    return $this->rawResponse;
  }

  /**
   * Create success result.
   */
  public static function success(string $transactionId, string $status, array $rawResponse = []): self {
    return new self(
      success: true,
      transactionId: $transactionId,
      status: $status,
      rawResponse: $rawResponse,
    );
  }

  /**
   * Create failure result.
   */
  public static function failure(string $error, array $rawResponse = []): self {
    return new self(
      success: false,
      error: $error,
      rawResponse: $rawResponse,
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
    ];
  }
}