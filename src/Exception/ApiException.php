<?php

namespace Drupal\webform_securepay\Exception;

/**
 * Exception for API communication errors.
 */
class ApiException extends PaymentException {

  /**
   * Creates an API exception for authentication failures.
   */
  public static function authenticationFailed(string $message = 'Invalid credentials'): self {
    return new self("Authentication failed: {$message}");
  }

  /**
   * Creates an API exception for network timeouts.
   */
  public static function timeout(int $seconds): self {
    return new self("API request timed out after {$seconds} seconds");
  }

  /**
   * Creates an API exception for HTTP errors.
   */
  public static function httpError(int $statusCode, string $message = ''): self {
    $errorMessage = "HTTP {$statusCode} error";
    if (!empty($message)) {
      $errorMessage .= ": {$message}";
    }
    return new self($errorMessage, $statusCode);
  }

  /**
   * Creates an API exception for invalid responses.
   */
  public static function invalidResponse(string $reason = 'Malformed response'): self {
    return new self("Invalid API response: {$reason}");
  }

  /**
   * Creates an API exception for service unavailability.
   */
  public static function serviceUnavailable(): self {
    return new self('SecurePay service is currently unavailable. Please try again later.');
  }

  /**
   * Creates an API exception for rate limiting by the API.
   */
  public static function apiRateLimited(): self {
    return new self('API rate limit exceeded. Please reduce request frequency.');
  }

  /**
   * Creates an API exception for maintenance mode.
   */
  public static function maintenanceMode(): self {
    return new self('SecurePay API is in maintenance mode. Please try again later.');
  }
}