<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for validation errors.
 */
class ValidationException extends PaymentException {

  // Validation error codes
  public const INVALID_AMOUNT = 'INVALID_AMOUNT';
  public const UNSUPPORTED_CURRENCY = 'UNSUPPORTED_CURRENCY';
  public const INVALID_TOKEN = 'INVALID_TOKEN';
  public const MISSING_MERCHANT_CODE = 'MISSING_MERCHANT_CODE';
  public const INVALID_CARD_TYPE = 'INVALID_CARD_TYPE';
  public const REQUIRED_FIELD = 'REQUIRED_FIELD';
  public const INVALID_FORMAT = 'INVALID_FORMAT';
  public const VALUE_TOO_LONG = 'VALUE_TOO_LONG';
  public const VALUE_TOO_SHORT = 'VALUE_TOO_SHORT';

  /**
   * Creates a validation exception for invalid amounts.
   */
  public static function invalidAmount(int $amount, int $min, int $max): self {
    return new self(
      "Amount {$amount} cents is invalid. Must be between {$min} and {$max} cents.",
      0,
      null,
      self::INVALID_AMOUNT,
      ['amount' => $amount, 'min' => $min, 'max' => $max]
    );
  }

  /**
   * Creates a validation exception for unsupported currencies.
   */
  public static function unsupportedCurrency(string $currency, array $supported): self {
    $supportedList = implode(', ', $supported);
    return new self(
      "Currency '{$currency}' is not supported. Supported currencies: {$supportedList}",
      0,
      null,
      self::UNSUPPORTED_CURRENCY,
      ['currency' => $currency, 'supported' => $supported]
    );
  }

  /**
   * Creates a validation exception for invalid tokens.
   */
  public static function invalidToken(string $reason = 'Invalid format'): self {
    return new self(
      "Payment token validation failed: {$reason}",
      0,
      null,
      self::INVALID_TOKEN,
      ['reason' => $reason]
    );
  }

  /**
   * Creates a validation exception for missing merchant code.
   */
  public static function missingMerchantCode(): self {
    return new self(
      'Merchant code is required for payment processing',
      0,
      null,
      self::MISSING_MERCHANT_CODE
    );
  }

  /**
   * Creates a validation exception for invalid card types.
   */
  public static function invalidCardType(string $cardType, array $allowed): self {
    $allowedList = implode(', ', $allowed);
    return new self(
      "Card type '{$cardType}' is not allowed. Allowed types: {$allowedList}",
      0,
      null,
      self::INVALID_CARD_TYPE,
      ['card_type' => $cardType, 'allowed' => $allowed]
    );
  }

  /**
   * Creates a validation exception for required fields.
   */
  public static function requiredField(string $field): self {
    return new self(
      "Required field '{$field}' is missing or empty",
      0,
      null,
      self::REQUIRED_FIELD,
      ['field' => $field]
    );
  }

  /**
   * Creates a validation exception for invalid format.
   */
  public static function invalidFormat(string $field, string $expectedFormat): self {
    return new self(
      "Field '{$field}' has invalid format. Expected: {$expectedFormat}",
      0,
      null,
      self::INVALID_FORMAT,
      ['field' => $field, 'expected_format' => $expectedFormat]
    );
  }

  /**
   * Creates a validation exception for values that are too long.
   */
  public static function valueTooLong(string $field, int $maxLength, int $actualLength): self {
    return new self(
      "Field '{$field}' is too long. Maximum {$maxLength} characters, got {$actualLength}",
      0,
      null,
      self::VALUE_TOO_LONG,
      ['field' => $field, 'max_length' => $maxLength, 'actual_length' => $actualLength]
    );
  }

  /**
   * Creates a validation exception for values that are too short.
   */
  public static function valueTooShort(string $field, int $minLength, int $actualLength): self {
    return new self(
      "Field '{$field}' is too short. Minimum {$minLength} characters, got {$actualLength}",
      0,
      null,
      self::VALUE_TOO_SHORT,
      ['field' => $field, 'min_length' => $minLength, 'actual_length' => $actualLength]
    );
  }

  /**
   * Creates a validation exception for email format.
   */
  public static function invalidEmail(string $email): self {
    return new self(
      "Invalid email address format: {$email}",
      0,
      null,
      self::INVALID_FORMAT,
      ['field' => 'email', 'value' => $email]
    );
  }

  /**
   * Creates a validation exception for phone number format.
   */
  public static function invalidPhoneNumber(string $phone): self {
    return new self(
      "Invalid phone number format: {$phone}",
      0,
      null,
      self::INVALID_FORMAT,
      ['field' => 'phone', 'value' => $phone]
    );
  }

  /**
   * Creates a validation exception for IP address format.
   */
  public static function invalidIpAddress(string $ip): self {
    return new self(
      "Invalid IP address format: {$ip}",
      0,
      null,
      self::INVALID_FORMAT,
      ['field' => 'ip_address', 'value' => $ip]
    );
  }

  /**
   * Creates a validation exception for URL format.
   */
  public static function invalidUrl(string $url): self {
    return new self(
      "Invalid URL format: {$url}",
      0,
      null,
      self::INVALID_FORMAT,
      ['field' => 'url', 'value' => $url]
    );
  }

  /**
   * Creates a validation exception for date format.
   */
  public static function invalidDate(string $date, string $expectedFormat = 'Y-m-d'): self {
    return new self(
      "Invalid date format: {$date}. Expected format: {$expectedFormat}",
      0,
      null,
      self::INVALID_FORMAT,
      ['field' => 'date', 'value' => $date, 'expected_format' => $expectedFormat]
    );
  }

  /**
   * Creates a validation exception for numeric values.
   */
  public static function invalidNumeric(string $field, mixed $value): self {
    return new self(
      "Field '{$field}' must be numeric, got: " . gettype($value),
      0,
      null,
      self::INVALID_FORMAT,
      ['field' => $field, 'value' => $value, 'type' => gettype($value)]
    );
  }

  /**
   * Creates a validation exception for boolean values.
   */
  public static function invalidBoolean(string $field, mixed $value): self {
    return new self(
      "Field '{$field}' must be boolean, got: " . gettype($value),
      0,
      null,
      self::INVALID_FORMAT,
      ['field' => $field, 'value' => $value, 'type' => gettype($value)]
    );
  }

  /**
   * Creates a validation exception for range violations.
   */
  public static function outOfRange(string $field, mixed $value, mixed $min, mixed $max): self {
    return new self(
      "Field '{$field}' value {$value} is out of range. Must be between {$min} and {$max}",
      0,
      null,
      self::INVALID_AMOUNT,
      ['field' => $field, 'value' => $value, 'min' => $min, 'max' => $max]
    );
  }

  /**
   * Creates a validation exception for duplicate values.
   */
  public static function duplicateValue(string $field, mixed $value): self {
    return new self(
      "Duplicate value for field '{$field}': {$value}",
      0,
      null,
      self::INVALID_FORMAT,
      ['field' => $field, 'value' => $value]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMessage(): string {
    // Validation errors are typically safe to show to users
    return $this->getMessage();
  }

  /**
   * {@inheritdoc}
   */
  public function isRetryable(): bool {
    // Validation errors are not retryable - user input needs to be corrected
    return false;
  }
}