<?php

namespace Drupal\webform_securepay\ValueObject;

use Drupal\webform_securepay\Exception\ValidationException;

/**
 * Payment request value object with improved validation.
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
    $this->token = $token;
    $this->amount = $amount;
    $this->currency = $currency;
    $this->merchantCode = $merchantCode;
    $this->orderId = $orderId;
    $this->ipAddress = $ipAddress;
    $this->userAgent = $userAgent;
    $this->dccQuote = $dccQuote;
    $this->threeDSResult = $threeDSResult;
    
    $this->validate();
  }

  /**
   * Get token.
   */
  public function getToken(): string {
    return $this->token;
  }

  /**
   * Get amount.
   */
  public function getAmount(): int {
    return $this->amount;
  }

  /**
   * Get currency.
   */
  public function getCurrency(): string {
    return $this->currency;
  }

  /**
   * Get merchant code.
   */
  public function getMerchantCode(): string {
    return $this->merchantCode;
  }

  /**
   * Get order ID.
   */
  public function getOrderId(): string {
    return $this->orderId;
  }

  /**
   * Get IP address.
   */
  public function getIpAddress(): ?string {
    return $this->ipAddress;
  }

  /**
   * Get user agent.
   */
  public function getUserAgent(): ?string {
    return $this->userAgent;
  }

  /**
   * Get DCC quote.
   */
  public function getDccQuote(): ?array {
    return $this->dccQuote;
  }

  /**
   * Get 3DS result.
   */
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
   * Create from array data with validation.
   */
  public static function fromArray(array $data): self {
    return new self(
      $data['token'] ?? '',
      (int) ($data['amount'] ?? 0),
      $data['currency'] ?? 'AUD',
      $data['merchantCode'] ?? '',
      $data['orderId'] ?? '',
      $data['ipAddress'] ?? $data['ip'] ?? null,
      $data['userAgent'] ?? null,
      $data['dccQuote'] ?? null,
      $data['threeDSResult'] ?? null
    );
  }

  /**
   * Get formatted amount for display.
   */
  public function getFormattedAmount(): string {
    return $this->currency . ' ' . number_format($this->amount / 100, 2);
  }

  /**
   * Validate all properties.
   */
  private function validate(): void {
    $this->validateToken();
    $this->validateAmount();
    $this->validateCurrency();
    $this->validateMerchantCode();
    $this->validateOrderId();
    
    if ($this->ipAddress) {
      $this->validateIpAddress();
    }
  }

  /**
   * Validate payment token.
   */
  private function validateToken(): void {
    $trimmedToken = trim($this->token);
    
    if (empty($trimmedToken)) {
      throw ValidationException::invalidToken('Token cannot be empty');
    }
    
    if (strlen($trimmedToken) < 10) {
      throw ValidationException::invalidToken('Token is too short (minimum 10 characters)');
    }
    
    if (strlen($trimmedToken) > 255) {
      throw ValidationException::invalidToken('Token is too long (maximum 255 characters)');
    }
    
    // Check for basic token format (alphanumeric with allowed special chars)
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $trimmedToken)) {
      throw ValidationException::invalidToken('Token contains invalid characters');
    }
  }

  /**
   * Validate payment amount.
   */
  private function validateAmount(): void {
    if ($this->amount <= 0) {
      throw ValidationException::invalidAmount($this->amount, 1, 99999999);
    }
    
    if ($this->amount > 99999999) { // $999,999.99
      throw ValidationException::invalidAmount($this->amount, 1, 99999999);
    }
  }

  /**
   * Validate currency code.
   */
  private function validateCurrency(): void {
    $supportedCurrencies = ['AUD', 'USD', 'EUR', 'GBP', 'NZD', 'CAD', 'JPY', 'SGD'];
    
    if (!in_array($this->currency, $supportedCurrencies, true)) {
      throw ValidationException::unsupportedCurrency($this->currency, $supportedCurrencies);
    }
  }

  /**
   * Validate merchant code.
   */
  private function validateMerchantCode(): void {
    if (empty(trim($this->merchantCode))) {
      throw ValidationException::missingMerchantCode();
    }
    
    // Merchant codes are typically alphanumeric
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $this->merchantCode)) {
      throw ValidationException::requiredField('Valid merchant code (alphanumeric only)');
    }
  }

  /**
   * Validate order ID format.
   */
  private function validateOrderId(): void {
    if (empty(trim($this->orderId))) {
      throw ValidationException::requiredField('order_id');
    }
    
    if (strlen($this->orderId) > 100) {
      throw ValidationException::invalidToken('Order ID is too long (maximum 100 characters)');
    }
    
    // Order IDs should be URL-safe
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $this->orderId)) {
      throw ValidationException::invalidToken('Order ID contains invalid characters (alphanumeric, underscore, and hyphen only)');
    }
  }

  /**
   * Validate IP address format.
   */
  private function validateIpAddress(): void {
    if (!filter_var($this->ipAddress, FILTER_VALIDATE_IP)) {
      throw ValidationException::invalidToken('Invalid IP address format: ' . $this->ipAddress);
    }
  }
}