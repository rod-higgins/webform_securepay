<?php

namespace Drupal\webform_securepay\ValueObject;

use Drupal\webform_securepay\Exception\ValidationException;
use Drupal\webform_securepay\Service\ConfigurationService;

/**
 * Payment request value object.
 */
class PaymentRequest {

  // Validation constants
  private const MIN_TOKEN_LENGTH = 10;
  private const MAX_TOKEN_LENGTH = 1000;
  private const MIN_AMOUNT = 1;
  private const MAX_AMOUNT = 999999999;
  private const TOKEN_PATTERN = '/^[a-zA-Z0-9_-]+$/';
  private const ORDER_ID_PATTERN = '/^[a-zA-Z0-9_-]+$/';
  private const MERCHANT_CODE_PATTERN = '/^[a-zA-Z0-9_-]+$/';
  private const MAX_ORDER_ID_LENGTH = 255;
  private const MAX_MERCHANT_CODE_LENGTH = 50;

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

  /**
   * Get the payment token.
   */
  public function getToken(): string {
    return $this->token;
  }

  /**
   * Get the payment amount in cents.
   */
  public function getAmount(): int {
    return $this->amount;
  }

  /**
   * Get the payment currency.
   */
  public function getCurrency(): string {
    return $this->currency;
  }

  /**
   * Get the merchant code.
   */
  public function getMerchantCode(): string {
    return $this->merchantCode;
  }

  /**
   * Get the order ID.
   */
  public function getOrderId(): string {
    return $this->orderId;
  }

  /**
   * Get the client IP address.
   */
  public function getIpAddress(): ?string {
    return $this->ipAddress;
  }

  /**
   * Convert to array for API requests.
   */
  public function toArray(): array {
    $data = [
      'token' => $this->token,
      'amount' => $this->amount,
      'currency' => $this->currency,
      'merchantCode' => $this->merchantCode,
      'orderId' => $this->orderId,
    ];

    if ($this->ipAddress !== null) {
      $data['ip'] = $this->ipAddress;
    }

    return $data;
  }

  /**
   * Create from array data.
   */
  public static function fromArray(array $data): self {
    return new self(
      token: self::sanitizeToken($data['token'] ?? ''),
      amount: self::sanitizeAmount($data['amount'] ?? 0),
      currency: self::sanitizeCurrency($data['currency'] ?? ConfigurationService::CURRENCY_AUD),
      merchantCode: self::sanitizeMerchantCode($data['merchantCode'] ?? ''),
      orderId: self::sanitizeOrderId($data['orderId'] ?? ''),
      ipAddress: self::sanitizeIpAddress($data['ipAddress'] ?? null),
    );
  }

  /**
   * Validate payment request data.
   */
  private function validate(): void {
    // Validate token
    if (empty(trim($this->token))) {
      throw ValidationException::requiredField('token');
    }
    
    if (strlen($this->token) < self::MIN_TOKEN_LENGTH || strlen($this->token) > self::MAX_TOKEN_LENGTH) {
      throw ValidationException::invalidToken('Token length must be between ' . self::MIN_TOKEN_LENGTH . ' and ' . self::MAX_TOKEN_LENGTH . ' characters');
    }

    if (!preg_match(self::TOKEN_PATTERN, $this->token)) {
      throw ValidationException::invalidToken('Token contains invalid characters');
    }

    // Validate amount
    if ($this->amount < self::MIN_AMOUNT || $this->amount > self::MAX_AMOUNT) {
      throw ValidationException::invalidAmount($this->amount, self::MIN_AMOUNT, self::MAX_AMOUNT);
    }

    // Validate currency
    if (!ConfigurationService::isValidCurrency($this->currency)) {
      throw ValidationException::unsupportedCurrency($this->currency, ConfigurationService::getValidCurrencies());
    }

    // Validate merchant code
    if (empty(trim($this->merchantCode))) {
      throw ValidationException::missingMerchantCode();
    }

    if (strlen($this->merchantCode) > self::MAX_MERCHANT_CODE_LENGTH) {
      throw ValidationException::validation('Merchant code too long');
    }

    if (!preg_match(self::MERCHANT_CODE_PATTERN, $this->merchantCode)) {
      throw ValidationException::validation('Merchant code contains invalid characters');
    }

    // Validate order ID
    if (empty(trim($this->orderId))) {
      throw ValidationException::requiredField('orderId');
    }

    if (strlen($this->orderId) > self::MAX_ORDER_ID_LENGTH) {
      throw ValidationException::validation('Order ID too long');
    }

    if (!preg_match(self::ORDER_ID_PATTERN, $this->orderId)) {
      throw ValidationException::validation('Order ID contains invalid characters');
    }

    // Validate IP address if provided
    if ($this->ipAddress !== null && !filter_var($this->ipAddress, FILTER_VALIDATE_IP)) {
      throw ValidationException::validation('Invalid IP address format');
    }
  }

  /**
   * Sanitize payment token.
   */
  private static function sanitizeToken(mixed $token): string {
    if (!is_string($token) && !is_numeric($token)) {
      throw ValidationException::invalidToken('Token must be a string');
    }

    $sanitized = trim((string) $token);
    if (empty($sanitized)) {
      throw ValidationException::requiredField('token');
    }

    return $sanitized;
  }

  /**
   * Sanitize payment amount.
   */
  private static function sanitizeAmount(mixed $amount): int {
    if (!is_numeric($amount)) {
      throw ValidationException::validation('Amount must be numeric');
    }

    $sanitized = (int) $amount;
    if ($sanitized < self::MIN_AMOUNT) {
      throw ValidationException::invalidAmount($sanitized, self::MIN_AMOUNT, self::MAX_AMOUNT);
    }

    return $sanitized;
  }

  /**
   * Sanitize currency code.
   */
  private static function sanitizeCurrency(mixed $currency): string {
    if (!is_string($currency)) {
      throw ValidationException::validation('Currency must be a string');
    }

    $sanitized = strtoupper(trim($currency));
    if (empty($sanitized)) {
      $sanitized = ConfigurationService::CURRENCY_AUD; // Default fallback
    }

    return $sanitized;
  }

  /**
   * Sanitize merchant code.
   */
  private static function sanitizeMerchantCode(mixed $merchantCode): string {
    if (!is_string($merchantCode) && !is_numeric($merchantCode)) {
      throw ValidationException::validation('Merchant code must be a string');
    }

    $sanitized = trim((string) $merchantCode);
    if (empty($sanitized)) {
      throw ValidationException::missingMerchantCode();
    }

    return $sanitized;
  }

  /**
   * Sanitize order ID.
   */
  private static function sanitizeOrderId(mixed $orderId): string {
    if (!is_string($orderId) && !is_numeric($orderId)) {
      throw ValidationException::validation('Order ID must be a string');
    }

    $sanitized = trim((string) $orderId);
    if (empty($sanitized)) {
      throw ValidationException::requiredField('orderId');
    }

    return $sanitized;
  }

  /**
   * Sanitize IP address.
   */
  private static function sanitizeIpAddress(mixed $ipAddress): ?string {
    if ($ipAddress === null || $ipAddress === '') {
      return null;
    }

    if (!is_string($ipAddress)) {
      return null;
    }

    $sanitized = trim($ipAddress);
    if (empty($sanitized)) {
      return null;
    }

    // Validate IP format
    if (!filter_var($sanitized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
      return null; // Invalid IP, return null instead of throwing exception
    }

    return $sanitized;
  }

  /**
   * Create a copy with different amount.
   */
  public function withAmount(int $amount): self {
    return new self(
      $this->token,
      $amount,
      $this->currency,
      $this->merchantCode,
      $this->orderId,
      $this->ipAddress
    );
  }

  /**
   * Create a copy with different currency.
   */
  public function withCurrency(string $currency): self {
    return new self(
      $this->token,
      $this->amount,
      $currency,
      $this->merchantCode,
      $this->orderId,
      $this->ipAddress
    );
  }

  /**
   * Create a copy with different order ID.
   */
  public function withOrderId(string $orderId): self {
    return new self(
      $this->token,
      $this->amount,
      $this->currency,
      $this->merchantCode,
      $orderId,
      $this->ipAddress
    );
  }
}