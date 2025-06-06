<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for SecurePay API errors.
 */
class ApiException extends PaymentException {

  // API error codes
  public const AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED';
  public const HTTP_ERROR = 'HTTP_ERROR';
  public const RATE_LIMITED = 'RATE_LIMITED';
  public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
  public const INVALID_RESPONSE = 'INVALID_RESPONSE';
  public const MISSING_REQUIRED_DATA = 'MISSING_REQUIRED_DATA';
  public const UNSUPPORTED_OPERATION = 'UNSUPPORTED_OPERATION';
  public const QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
  public const MAINTENANCE_MODE = 'MAINTENANCE_MODE';
  public const CONNECTION_TIMEOUT = 'CONNECTION_TIMEOUT';
  public const SSL_ERROR = 'SSL_ERROR';
  public const DNS_ERROR = 'DNS_ERROR';
  public const MALFORMED_JSON = 'MALFORMED_JSON';
  public const WEBHOOK_VALIDATION_FAILED = 'WEBHOOK_VALIDATION_FAILED';
  public const ORDER_NOT_FOUND = 'ORDER_NOT_FOUND';
  public const DUPLICATE_ORDER = 'DUPLICATE_ORDER';
  public const INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';
  public const CARD_DECLINED = 'CARD_DECLINED';
  public const FRAUD_DETECTED = 'FRAUD_DETECTED';
  public const EXPIRED_CARD = 'EXPIRED_CARD';
  public const INVALID_CARD = 'INVALID_CARD';

  /**
   * Creates an API exception for authentication failures.
   */
  public static function authenticationFailed(string $reason = 'Invalid credentials'): self {
    return new self(
      "SecurePay API authentication failed: {$reason}",
      401,
      null,
      self::AUTHENTICATION_FAILED,
      ['reason' => $reason]
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
      self::HTTP_ERROR,
      ['status_code' => $statusCode, 'message' => $message]
    );
  }

  /**
   * Creates an API exception for rate limiting.
   */
  public static function apiRateLimited(int $retryAfter = 60): self {
    return new self(
      'SecurePay API rate limit exceeded. Please try again later.',
      429,
      null,
      self::RATE_LIMITED,
      ['retry_after' => $retryAfter]
    );
  }

  /**
   * Creates an API exception for service unavailability.
   */
  public static function serviceUnavailable(string $reason = 'Service temporarily unavailable'): self {
    return new self(
      "SecurePay API is temporarily unavailable: {$reason}",
      503,
      null,
      self::SERVICE_UNAVAILABLE,
      ['reason' => $reason]
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
      self::INVALID_RESPONSE,
      ['reason' => $reason]
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
      self::MISSING_REQUIRED_DATA,
      ['field' => $field]
    );
  }

  /**
   * Creates an API exception for unsupported operations.
   */
  public static function unsupportedOperation(string $operation, string $apiVersion = 'current'): self {
    return new self(
      "Operation '{$operation}' is not supported by the {$apiVersion} SecurePay API version",
      0,
      null,
      self::UNSUPPORTED_OPERATION,
      ['operation' => $operation, 'api_version' => $apiVersion]
    );
  }

  /**
   * Creates an API exception for quota exceeded.
   */
  public static function quotaExceeded(string $quotaType, int $limit = 0): self {
    $message = "SecurePay API quota exceeded for {$quotaType}";
    if ($limit > 0) {
      $message .= " (limit: {$limit})";
    }
    
    return new self(
      $message,
      429,
      null,
      self::QUOTA_EXCEEDED,
      ['quota_type' => $quotaType, 'limit' => $limit]
    );
  }

  /**
   * Creates an API exception for maintenance mode.
   */
  public static function maintenanceMode(string $estimatedDuration = ''): self {
    $message = 'SecurePay API is in maintenance mode. Please try again later.';
    if (!empty($estimatedDuration)) {
      $message .= " Estimated duration: {$estimatedDuration}";
    }
    
    return new self(
      $message,
      503,
      null,
      self::MAINTENANCE_MODE,
      ['estimated_duration' => $estimatedDuration]
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
      self::CONNECTION_TIMEOUT,
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
      self::SSL_ERROR,
      ['details' => $details]
    );
  }

  /**
   * Creates an API exception for DNS resolution failures.
   */
  public static function dnsError(string $hostname = 'SecurePay API'): self {
    return new self(
      "Unable to resolve {$hostname} hostname. Please check your network connection.",
      0,
      null,
      self::DNS_ERROR,
      ['hostname' => $hostname]
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
      self::MALFORMED_JSON,
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
      self::WEBHOOK_VALIDATION_FAILED,
      ['reason' => $reason]
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
      self::ORDER_NOT_FOUND,
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
      self::DUPLICATE_ORDER,
      ['order_id' => $orderId]
    );
  }

  /**
   * Creates an API exception for insufficient funds.
   */
  public static function insufficientFunds(string $orderId): self {
    return new self(
      "Insufficient funds for order '{$orderId}'",
      402,
      null,
      self::INSUFFICIENT_FUNDS,
      ['order_id' => $orderId]
    );
  }

  /**
   * Creates an API exception for card declined.
   */
  public static function cardDeclined(string $reason = 'Card declined'): self {
    return new self(
      "Card payment declined: {$reason}",
      402,
      null,
      self::CARD_DECLINED,
      ['reason' => $reason]
    );
  }

  /**
   * Creates an API exception for fraud detection.
   */
  public static function fraudDetected(string $orderId, string $reason = 'Suspicious activity detected'): self {
    return new self(
      "Fraud detected for order '{$orderId}': {$reason}",
      403,
      null,
      self::FRAUD_DETECTED,
      ['order_id' => $orderId, 'reason' => $reason]
    );
  }

  /**
   * Creates an API exception for expired card.
   */
  public static function expiredCard(): self {
    return new self(
      'Payment failed: Card has expired',
      402,
      null,
      self::EXPIRED_CARD
    );
  }

  /**
   * Creates an API exception for invalid card.
   */
  public static function invalidCard(string $reason = 'Invalid card details'): self {
    return new self(
      "Payment failed: {$reason}",
      402,
      null,
      self::INVALID_CARD,
      ['reason' => $reason]
    );
  }

  /**
   * Creates an API exception from HTTP response.
   */
  public static function fromHttpResponse(int $statusCode, string $responseBody = '', array $headers = []): self {
    $message = "HTTP {$statusCode}";
    $errorCode = self::HTTP_ERROR;
    $context = ['status_code' => $statusCode, 'headers' => $headers];

    // Try to parse error response
    if (!empty($responseBody)) {
      $decoded = json_decode($responseBody, true);
      if (json_last_error() === JSON_ERROR_NONE && isset($decoded['error'])) {
        $message = $decoded['error'];
        $context['response_body'] = $decoded;
      } else {
        $context['response_body'] = $responseBody;
      }
    }

    // Map specific status codes to error codes
    switch ($statusCode) {
      case 401:
        $errorCode = self::AUTHENTICATION_FAILED;
        break;
      case 402:
        $errorCode = self::CARD_DECLINED;
        break;
      case 403:
        $errorCode = self::FRAUD_DETECTED;
        break;
      case 404:
        $errorCode = self::ORDER_NOT_FOUND;
        break;
      case 409:
        $errorCode = self::DUPLICATE_ORDER;
        break;
      case 429:
        $errorCode = self::RATE_LIMITED;
        break;
      case 503:
        $errorCode = self::SERVICE_UNAVAILABLE;
        break;
    }

    return new self($message, $statusCode, null, $errorCode, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMessage(): string {
    // Provide user-friendly messages for common API errors
    return match ($this->getErrorCode()) {
      self::AUTHENTICATION_FAILED => 'Payment service authentication error. Please contact support.',
      self::RATE_LIMITED => 'Too many payment requests. Please wait a moment and try again.',
      self::SERVICE_UNAVAILABLE, self::MAINTENANCE_MODE => 'Payment service temporarily unavailable. Please try again later.',
      self::CONNECTION_TIMEOUT => 'Payment request timed out. Please try again.',
      self::INSUFFICIENT_FUNDS => 'Payment declined: Insufficient funds.',
      self::CARD_DECLINED => 'Payment declined. Please check your card details or try a different card.',
      self::FRAUD_DETECTED => 'Payment blocked for security reasons. Please contact your bank.',
      self::EXPIRED_CARD => 'Payment declined: Card has expired.',
      self::INVALID_CARD => 'Payment declined: Invalid card details.',
      self::ORDER_NOT_FOUND => 'Payment order not found. Please try again.',
      self::DUPLICATE_ORDER => 'This payment has already been processed.',
      default => 'Payment service error. Please try again later.',
    };
  }

  /**
   * {@inheritdoc}
   */
  public function isRetryable(): bool {
    return in_array($this->getErrorCode(), [
      self::RATE_LIMITED,
      self::SERVICE_UNAVAILABLE,
      self::CONNECTION_TIMEOUT,
      self::DNS_ERROR,
      self::HTTP_ERROR, // Some HTTP errors might be retryable
    ], true);
  }

  /**
   * Get retry delay in seconds.
   */
  public function getRetryDelay(): int {
    $context = $this->getContext();
    
    return match ($this->getErrorCode()) {
      self::RATE_LIMITED => $context['retry_after'] ?? 60,
      self::SERVICE_UNAVAILABLE => 30,
      self::CONNECTION_TIMEOUT => 5,
      default => 10,
    };
  }
}