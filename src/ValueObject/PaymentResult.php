<?php

namespace Drupal\webform_securepay\ValueObject;

use Drupal\webform_securepay\Exception\PaymentException;

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
  ) {}

  public static function fromApiResponse(array $response): self {
    $success = ($response['status'] ?? '') === 'paid';
    
    return new self(
      success: $success,
      transactionId: $response['orderId'] ?? '',
      status: $response['status'] ?? 'unknown',
      amount: (int) ($response['amount'] ?? 0),
      currency: $response['currency'] ?? 'AUD',
      gatewayResponseCode: $response['gatewayResponseCode'] ?? null,
      gatewayResponseMessage: $response['gatewayResponseMessage'] ?? null,
      bankTransactionId: $response['bankTransactionId'] ?? null,
      rawResponse: $response,
      error: $success ? null : ($response['error'] ?? 'Payment failed'),
      errorCode: $success ? null : ($response['errorCode'] ?? null),
    );
  }

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
    ];
  }
}