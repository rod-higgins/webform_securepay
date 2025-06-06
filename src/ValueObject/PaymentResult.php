<?php

namespace Drupal\webform_securepay\ValueObject;

use Drupal\webform_securepay\Exception\PaymentException;

/**
 * Payment request value object.
 */
class PaymentRequest {

  private string $token;
  private int $amount;
  private string $currency;
  private string $merchantCode;
  private string $orderId;
  private ?string $ipAddress;
  private ?string $userAgent;
  private ?array $dccQuote;
  private ?array $threeDSResult;

  public function __construct(
    string $token,
    int $amount,
    string $currency,
    string $merchantCode,
    string $orderId,
    ?string $ipAddress = null,
    ?string $userAgent = null,
    ?array $dccQuote = null,
    ?array $threeDSResult = null
  ) {
    if ($amount <= 0) {
      throw new PaymentException('Amount must be greater than zero');
    }
    
    if (empty(trim($token))) {
      throw new PaymentException('Payment token is required');
    }
    
    if (empty(trim($merchantCode))) {
      throw new PaymentException('Merchant code is required');
    }
    
    if (empty(trim($orderId))) {
      throw new PaymentException('Order ID is required');
    }

    $this->token = $token;
    $this->amount = $amount;
    $this->currency = $currency;
    $this->merchantCode = $merchantCode;
    $this->orderId = $orderId;
    $this->ipAddress = $ipAddress;
    $this->userAgent = $userAgent;
    $this->dccQuote = $dccQuote;
    $this->threeDSResult = $threeDSResult;
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

  public function getUserAgent(): ?string {
    return $this->userAgent;
  }

  public function getDccQuote(): ?array {
    return $this->dccQuote;
  }

  public function getThreeDSResult(): ?array {
    return $this->threeDSResult;
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
      'userAgent' => $this->userAgent,
      'dccQuote' => $this->dccQuote,
      'threeDSResult' => $this->threeDSResult,
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
      userAgent: $data['userAgent'] ?? null,
      dccQuote: $data['dccQuote'] ?? null,
      threeDSResult: $data['threeDSResult'] ?? null,
    );
  }
}