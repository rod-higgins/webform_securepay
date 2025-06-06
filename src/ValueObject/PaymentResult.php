<?php

namespace Drupal\webform_securepay\ValueObject;

/**
 * Payment result value object.
 */
readonly class PaymentResult {

  public function __construct(
    public bool $success,
    public string $transactionId,
    public string $status,
    public int $amount,
    public string $currency,
    public ?string $gatewayResponseCode = null,
    public ?string $gatewayResponseMessage = null,
    public ?string $bankTransactionId = null,
    public ?array $rawResponse = null,
    public ?string $error = null,
    public ?string $errorCode = null,
    public ?array $dccQuote = null,
    public ?array $threeDSResult = null,
    public ?array $fraudResult = null,
  ) {}

  /**
   * Create PaymentResult from SecurePay API response.
   */
  public static function fromApiResponse(array $response): self {
    $success = ($response['status'] ?? '') === 'paid';
    
    return new self(
      success: $success,
      transactionId: $response['orderId'] ?? $response['transactionId'] ?? '',
      status: $response['status'] ?? 'unknown',
      amount: (int) ($response['amount'] ?? 0),
      currency: $response['currency'] ?? 'AUD',
      gatewayResponseCode: $response['gatewayResponseCode'] ?? null,
      gatewayResponseMessage: $response['gatewayResponseMessage'] ?? null,
      bankTransactionId: $response['bankTransactionId'] ?? null,
      rawResponse: $response,
      error: $success ? null : ($response['error'] ?? $response['message'] ?? 'Payment failed'),
      errorCode: $success ? null : ($response['errorCode'] ?? $response['code'] ?? null),
      dccQuote: $response['dccQuote'] ?? null,
      threeDSResult: $response['threeDSResult'] ?? null,
      fraudResult: $response['fraudResult'] ?? null,
    );
  }

  /**
   * Create a successful payment result.
   */
  public static function success(
    string $transactionId,
    int $amount,
    string $currency = 'AUD',
    ?string $gatewayResponseCode = null,
    ?array $rawResponse = null
  ): self {
    return new self(
      success: true,
      transactionId: $transactionId,
      status: 'paid',
      amount: $amount,
      currency: $currency,
      gatewayResponseCode: $gatewayResponseCode,
      rawResponse: $rawResponse,
    );
  }

  /**
   * Create a failed payment result.
   */
  public static function failure(
    string $error,
    ?string $errorCode = null,
    ?string $transactionId = null,
    int $amount = 0,
    string $currency = 'AUD',
    ?array $rawResponse = null
  ): self {
    return new self(
      success: false,
      transactionId: $transactionId ?? '',
      status: 'failed',
      amount: $amount,
      currency: $currency,
      error: $error,
      errorCode: $errorCode,
      rawResponse: $rawResponse,
    );
  }

  /**
   * Convert to array for storage/display.
   */
  public function toArray(): array {
    return [
      'success' => $this->success,
      'transaction_id' => $this->transactionId,
      'status' => $this->status,
      'amount' => $this->amount,
      'currency' => $this->currency,
      'gateway_response_code' => $this->gatewayResponseCode,
      'gateway_response_message' => $this->gatewayResponseMessage,
      'bank_transaction_id' => $this->bankTransactionId,
      'error' => $this->error,
      'error_code' => $this->errorCode,
      'created_at' => date('c'),
      'dcc_quote' => $this->dccQuote,
      'three_ds_result' => $this->threeDSResult,
      'fraud_result' => $this->fraudResult,
    ];
  }

  /**
   * Get formatted amount for display.
   */
  public function getFormattedAmount(): string {
    return $this->currency . ' ' . number_format($this->amount / 100, 2);
  }

  /**
   * Check if payment is successful.
   */
  public function isSuccessful(): bool {
    return $this->success && $this->status === 'paid';
  }

  /**
   * Get human-readable status.
   */
  public function getStatusLabel(): string {
    return match($this->status) {
      'paid' => 'Paid',
      'pending' => 'Pending',
      'failed' => 'Failed',
      'cancelled' => 'Cancelled',
      'refunded' => 'Refunded',
      'partial_refund' => 'Partially Refunded',
      default => ucfirst($this->status),
    };
  }
}