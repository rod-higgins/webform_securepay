<?php

namespace Drupal\webform_securepay\Service;

use Drupal\webform_securepay\Exception\ValidationException;
use Drupal\webform_securepay\ValueObject\PaymentRequest;

/**
 * Validates payment requests against business rules.
 */
class PaymentValidator {

  // Payment amount limits (in cents)
  private const MIN_AMOUNT = 1;
  private const MAX_AMOUNT = 99999999; // $999,999.99

  // Supported currencies
  private const SUPPORTED_CURRENCIES = [
    'AUD', 'USD', 'EUR', 'GBP', 'NZD', 'CAD', 'JPY', 'SGD'
  ];

  // Supported card types
  private const SUPPORTED_CARD_TYPES = [
    'visa', 'mastercard', 'amex', 'diners'
  ];

  // Token validation rules
  private const MIN_TOKEN_LENGTH = 10;
  private const MAX_TOKEN_LENGTH = 255;

  /**
   * Validates a complete payment request.
   *
   * @param \Drupal\webform_securepay\ValueObject\PaymentRequest $request
   *   The payment request to validate.
   *
   * @throws \Drupal\webform_securepay\Exception\ValidationException
   *   When validation fails.
   */
  public function validate(PaymentRequest $request): void {
    $this->validateAmount($request->amount);
    $this->validateCurrency($request->currency);
    $this->validateToken($request->token);
    $this->validateMerchantCode($request->merchantCode);
    $this->validateOrderId($request->orderId);
    
    if ($request->ipAddress) {
      $this->validateIpAddress($request->ipAddress);
    }
  }

  /**
   * Validates payment amount.
   *
   * @param int $amount
   *   Amount in cents.
   *
   * @throws \Drupal\webform_securepay\Exception\ValidationException
   *   When amount is invalid.
   */
  public function validateAmount(int $amount): void {
    if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
      throw ValidationException::invalidAmount($amount, self::MIN_AMOUNT, self::MAX_AMOUNT);
    }
  }

  /**
   * Validates currency code.
   *
   * @param string $currency
   *   Three-letter currency code.
   *
   * @throws \Drupal\webform_securepay\Exception\ValidationException
   *   When currency is not supported.
   */
  public function validateCurrency(string $currency): void {
    if (!in_array($currency, self::SUPPORTED_CURRENCIES, TRUE)) {
      throw ValidationException::unsupportedCurrency($currency, self::SUPPORTED_CURRENCIES);
    }
  }

  /**
   * Validates payment token.
   *
   * @param string $token
   *   Payment token from SecurePay UI.
   *
   * @throws \Drupal\webform_securepay\Exception\ValidationException
   *   When token is invalid.
   */
  public function validateToken(string $token): void {
    $trimmedToken = trim($token);
    
    if (empty($trimmedToken)) {
      throw ValidationException::invalidToken('Token cannot be empty');
    }
    
    if (strlen($trimmedToken) < self::MIN_TOKEN_LENGTH) {
      throw ValidationException::invalidToken('Token is too short');
    }
    
    if (strlen($trimmedToken) > self::MAX_TOKEN_LENGTH) {
      throw ValidationException::invalidToken('Token is too long');
    }
    
    // Check for basic token format (alphanumeric with allowed special chars)
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $trimmedToken)) {
      throw ValidationException::invalidToken('Token contains invalid characters');
    }
  }

  /**
   * Validates merchant code.
   *
   * @param string $merchantCode
   *   SecurePay merchant code.
   *
   * @throws \Drupal\webform_securepay\Exception\ValidationException
   *   When merchant code is invalid.
   */
  public function validateMerchantCode(string $merchantCode): void {
    if (empty(trim($merchantCode))) {
      throw ValidationException::missingMerchantCode();
    }
    
    // Merchant codes are typically alphanumeric
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $merchantCode)) {
      throw ValidationException::requiredField('Valid merchant code');
    }
  }

  /**
   * Validates order ID format.
   *
   * @param string $orderId
   *   Unique order identifier.
   *
   * @throws \Drupal\webform_securepay\Exception\ValidationException
   *   When order ID is invalid.
   */
  public function validateOrderId(string $orderId): void {
    if (empty(trim($orderId))) {
      throw ValidationException::requiredField('order_id');
    }
    
    if (strlen($orderId) > 100) {
      throw ValidationException::invalidToken('Order ID is too long (max 100 characters)');
    }
    
    // Order IDs should be URL-safe
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $orderId)) {
      throw ValidationException::invalidToken('Order ID contains invalid characters');
    }
  }

  /**
   * Validates IP address format.
   *
   * @param string $ipAddress
   *   Client IP address.
   *
   * @throws \Drupal\webform_securepay\Exception\ValidationException
   *   When IP address is invalid.
   */
  public function validateIpAddress(string $ipAddress): void {
    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
      throw ValidationException::invalidToken('Invalid IP address format');
    }
  }

  /**
   * Validates card type against allowed types.
   *
   * @param string $cardType
   *   Card type (visa, mastercard, etc.).
   * @param array $allowedTypes
   *   Array of allowed card types.
   *
   * @throws \Drupal\webform_securepay\Exception\ValidationException
   *   When card type is not allowed.
   */
  public function validateCardType(string $cardType, array $allowedTypes): void {
    if (!in_array($cardType, $allowedTypes, TRUE)) {
      throw ValidationException::invalidCardType($cardType, $allowedTypes);
    }
    
    if (!in_array($cardType, self::SUPPORTED_CARD_TYPES, TRUE)) {
      throw ValidationException::invalidCardType($cardType, self::SUPPORTED_CARD_TYPES);
    }
  }

  /**
   * Validates DCC quote data.
   *
   * @param array $dccQuote
   *   DCC quote from SecurePay.
   *
   * @throws \Drupal\webform_securepay\Exception\ValidationException
   *   When DCC quote is invalid.
   */
  public function validateDccQuote(array $dccQuote): void {
    $requiredFields = ['converted', 'base', 'exchangeRate'];
    
    foreach ($requiredFields as $field) {
      if (!isset($dccQuote[$field])) {
        throw ValidationException::requiredField("dcc_quote.{$field}");
      }
    }
    
    // Validate converted amount
    if (!isset($dccQuote['converted']['amount']) || !is_numeric($dccQuote['converted']['amount'])) {
      throw ValidationException::invalidToken('DCC converted amount must be numeric');
    }
    
    // Validate exchange rate
    if (!isset($dccQuote['exchangeRate']['value']) || !is_numeric($dccQuote['exchangeRate']['value'])) {
      throw ValidationException::invalidToken('DCC exchange rate must be numeric');
    }
  }

  /**
   * Gets the list of supported currencies.
   *
   * @return array
   *   Array of supported currency codes.
   */
  public function getSupportedCurrencies(): array {
    return self::SUPPORTED_CURRENCIES;
  }

  /**
   * Gets the list of supported card types.
   *
   * @return array
   *   Array of supported card types.
   */
  public function getSupportedCardTypes(): array {
    return self::SUPPORTED_CARD_TYPES;
  }

  /**
   * Gets the minimum allowed amount.
   *
   * @return int
   *   Minimum amount in cents.
   */
  public function getMinAmount(): int {
    return self::MIN_AMOUNT;
  }

  /**
   * Gets the maximum allowed amount.
   *
   * @return int
   *   Maximum amount in cents.
   */
  public function getMaxAmount(): int {
    return self::MAX_AMOUNT;
  }
}