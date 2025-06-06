<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for validation errors.
 */
class ValidationException extends PaymentException {

  /**
   * Creates a validation exception for invalid amounts.
   */
  public static function invalidAmount(int $amount, int $min, int $max): self {
    return new self("Amount {$amount} cents is invalid. Must be between {$min} and {$max} cents.");
  }

  /**
   * Creates a validation exception for unsupported currencies.
   */
  public static function unsupportedCurrency(string $currency, array $supported): self {
    $supportedList = implode(', ', $supported);
    return new self("Currency '{$currency}' is not supported. Supported currencies: {$supportedList}");
  }

  /**
   * Creates a validation exception for invalid tokens.
   */
  public static function invalidToken(string $reason = 'Invalid format'): self {
    return new self("Payment token validation failed: {$reason}");
  }

  /**
   * Creates a validation exception for missing merchant code.
   */
  public static function missingMerchantCode(): self {
    return new self('Merchant code is required for payment processing');
  }

  /**
   * Creates a validation exception for invalid card types.
   */
  public static function invalidCardType(string $cardType, array $allowed): self {
    $allowedList = implode(', ', $allowed);
    return new self("Card type '{$cardType}' is not allowed. Allowed types: {$allowedList}");
  }

  /**
   * Creates a validation exception for required fields.
   */
  public static function requiredField(string $field): self {
    return new self("Required field '{$field}' is missing or empty");
  }
}