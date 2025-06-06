<?php

namespace Drupal\webform_securepay\ValueObject;

use Drupal\webform_securepay\Exception\PaymentException;

/**
 * Payment request value object.
 */
class PaymentRequest {

  public function __construct(
    private readonly string $token,
    private readonly int $amount,
    private readonly string $currency,
    private readonly string $merchantCode,
    private readonly string $orderId,
    private readonly ?string $ipAddress = null,
  ) {
    $this->validate();
  }

  public function getToken(): string {
    return $this->token;
  }

  public function getAmount(): int {
    return $this->amount;
  }

  public function getCurrency(): string {
    return $this->currency;
  }

  public function getMerchantCode(): string {
    return $this->merchantCode;
  }

  public function getOrderId(): string {
    return $this->orderId;
  }

  public function getIpAddress(): ?string {
    return $this->ipAddress;
  }

  /**
   * Convert to array for API requests.
   */
  public function toArray(): array {
    return [
      'token' => $this->token,
      'amount' => $this->amount,
      'currency' => $this->currency,
      'merchantCode' => $this->merchantCode,
      'orderId' => $this->orderId,
      'ip' => $this->ipAddress,
    ];
  }

  /**
   * Create from array data.
   */
  public static function fromArray(array $data): self {
    return new self(
      token: $data['token'] ?? '',
      amount: (int) ($data['amount'] ?? 0),
      currency: $data['currency'] ?? 'AUD',
      merchantCode: $data['merchantCode'] ?? '',
      orderId: $data['orderId'] ?? '',
      ipAddress: $data['ipAddress'] ?? null,
    );
  }

  /**
   * Validate payment request data.
   */
  private function validate(): void {
    if (empty(trim($this->token))) {
      throw PaymentException::validation('Payment token is required');
    }
    
    if ($this->amount <= 0) {
      throw PaymentException::validation('Amount must be greater than zero');
    }
    
    if (empty(trim($this->merchantCode))) {
      throw PaymentException::validation('Merchant code is required');
    }
    
    if (empty(trim($this->orderId))) {
      throw PaymentException::validation('Order ID is required');
    }
  }
}