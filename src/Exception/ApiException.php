<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for SecurePay API errors.
 */
class ApiException extends PaymentException {

  /**
   * Creates an API exception for authentication failures.
   */
  public static function authenticationFailed(string $reason = 'Invalid credentials'): self {
    return new self(
      "SecurePay API authentication failed: {$reason}",
      401,
      null,
      'AUTHENTICATION_FAILED'
    );
  }

  /**
   * Creates an API exception for HTTP errors.
   */
  public static function httpError(int $statusCode, string $message): self {
    return new self(
      "SecurePay API HTTP error ({$statusCode}): {$message}",
      $statusCode,
      null,
      'HTTP_ERROR',
      ['status_code' => $statusCode]
    );
  }

  /**
   * Creates an API exception for rate limiting.
   */
  public static function apiRateLimited(): self {
    return new self(
      'SecurePay API rate limit exceeded. Please try again later.',
      429,
      null,
      'RATE_LIMITED'
    );
  }

  /**
   * Creates an API exception for service unavailability.
   */
  public static function serviceUnavailable(): self {
    return new self(
      'SecurePay API is temporarily unavailable. Please try again later.',
      503,
      null,
      'SERVICE_UNAVAILABLE'
    );
  }

  /**
   * Creates an API exception for invalid responses.
   */
  public static function invalidResponse(string $reason): self {
    return new self(
      "Invalid response from SecurePay API: {$reason}",
      0,
      null,
      'INVALID_RESPONSE'
    );
  }

  /**
   * Creates an API exception for missing required data.
   */
  public static function missingRequiredData(string $field): self {
    return new self(
      "Required field '{$field}' missing in API response",
      0,
      null,
      'MISSING_REQUIRED_DATA',
      ['field' => $field]
    );
  }

  /**
   * Creates an API exception for unsupported operations.
   */
  public static function unsupportedOperation(string $operation): self {
    return new self(
      "Operation '{$operation}' is not supported by the current SecurePay API version",
      0,
      null,
      'UNSUPPORTED_OPERATION',
      ['operation' => $operation]
    );
  }

  /**
   * Creates an API exception for quota exceeded.
   */
  public static function quotaExceeded(string $quotaType): self {
    return new self(
      "SecurePay API quota exceeded for {$quotaType}",
      429,
      null,
      'QUOTA_EXCEEDED',
      ['quota_type' => $quotaType]
    );
  }

  /**
   * Creates an API exception for maintenance mode.
   */
  public static function maintenanceMode(): self {
    return new self(
      'SecurePay API is in maintenance mode. Please try again later.',
      503,
      null,
      'MAINTENANCE_MODE'
    );
  }

  /**
   * Creates an API exception for connection timeout.
   */
  public static function connectionTimeout(int $timeout): self {
    return new self(
      "Connection to SecurePay API timed out after {$timeout} seconds",
      0,
      null,
      'CONNECTION_TIMEOUT',
      ['timeout' => $timeout]
    );
  }

  /**
   * Creates an API exception for SSL/TLS errors.
   */
  public static function sslError(string $details): self {
    return new self(
      "SSL/TLS error connecting to SecurePay API: {$details}",
      0,
      null,
      'SSL_ERROR'
    );
  }

  /**
   * Creates an API exception for DNS resolution failures.
   */
  public static function dnsError(): self {
    return new self(
      'Unable to resolve SecurePay API hostname. Please check your network connection.',
      0,
      null,
      'DNS_ERROR'
    );
  }

  /**
   * Creates an API exception for malformed JSON responses.
   */
  public static function malformedJson(string $jsonError): self {
    return new self(
      "Malformed JSON response from SecurePay API: {$jsonError}",
      0,
      null,
      'MALFORMED_JSON',
      ['json_error' => $jsonError]
    );
  }

  /**
   * Creates an API exception for webhook validation failures.
   */
  public static function webhookValidationFailed(string $reason): self {
    return new self(
      "Webhook validation failed: {$reason}",
      401,
      null,
      'WEBHOOK_VALIDATION_FAILED'
    );
  }

  /**
   * Creates an API exception for order not found.
   */
  public static function orderNotFound(string $orderId): self {
    return new self(
      "Order '{$orderId}' not found in SecurePay API",
      404,
      null,
      'ORDER_NOT_FOUND',
      ['order_id' => $orderId]
    );
  }

  /**
   * Creates an API exception for duplicate order.
   */
  public static function duplicateOrder(string $orderId): self {
    return new self(
      "Order '{$orderId}' already exists in SecurePay API",
      409,
      null,
      'DUPLICATE_ORDER',
      ['order_id' => $orderId]
    );
  }
}