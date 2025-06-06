<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Database\Connection;

/**
 * Handles rate limiting for payment requests.
 */
class RateLimiter {

  /**
   * Database table name for tracking request attempts.
   */
  private const TABLE_NAME = 'webform_securepay_transactions';

  /**
   * Default rate limit window in seconds (1 hour).
   */
  private const DEFAULT_WINDOW = 3600;

  /**
   * Default maximum attempts per window.
   */
  private const DEFAULT_MAX_ATTEMPTS = 50;

  /**
   * Cleanup interval for old tracking data.
   */
  private const CLEANUP_INTERVAL = 86400; // 24 hours

  /**
   * Constructs a RateLimiter.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\webform_securepay\Service\ConfigurationService $configService
   *   The configuration service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ConfigurationService $configService,
  ) {}

  /**
   * Checks if the given IP address is rate limited.
   *
   * @param string $ipAddress
   *   The client IP address.
   *
   * @return bool
   *   TRUE if the IP address is rate limited.
   */
  public function isRateLimited(string $ipAddress): bool {
    if (!$this->isRateLimitingEnabled()) {
      return false;
    }

    // Validate IP address
    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
      // Invalid IP - consider it rate limited for security
      return true;
    }

    try {
      $attempts = $this->getRecentAttempts($ipAddress);
      $maxAttempts = $this->getMaxAttempts();
      
      return $attempts >= $maxAttempts;
    }
    catch (\Exception) {
      // On database errors, fail open (don't block legitimate users)
      return false;
    }
  }

  /**
   * Gets the current attempt count for an IP address.
   *
   * @param string $ipAddress
   *   The client IP address.
   *
   * @return int
   *   Number of recent attempts.
   */
  public function getAttemptCount(string $ipAddress): int {
    if (!$this->isRateLimitingEnabled() || !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
      return 0;
    }

    try {
      return $this->getRecentAttempts($ipAddress);
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Gets the remaining attempts for an IP address.
   *
   * @param string $ipAddress
   *   The client IP address.
   *
   * @return int
   *   Number of remaining attempts (0 if rate limited).
   */
  public function getRemainingAttempts(string $ipAddress): int {
    if (!$this->isRateLimitingEnabled()) {
      return $this->getMaxAttempts();
    }

    $currentAttempts = $this->getAttemptCount($ipAddress);
    $maxAttempts = $this->getMaxAttempts();
    
    return max(0, $maxAttempts - $currentAttempts);
  }

  /**
   * Gets the time until rate limit resets for an IP address.
   *
   * @param string $ipAddress
   *   The client IP address.
   *
   * @return int
   *   Seconds until reset (0 if not rate limited).
   */
  public function getTimeUntilReset(string $ipAddress): int {
    if (!$this->isRateLimited($ipAddress)) {
      return 0;
    }

    try {
      $oldestAttempt = $this->getOldestAttemptInWindow($ipAddress);
      if ($oldestAttempt) {
        $windowStart = $oldestAttempt + $this->getWindow();
        return max(0, $windowStart - time());
      }
    }
    catch (\Exception) {
      // On error, assume reset in one hour
      return self::DEFAULT_WINDOW;
    }

    return 0;
  }

  /**
   * Records a payment attempt for rate limiting purposes.
   * 
   * Note: This is automatically handled by TransactionLogger,
   * but can be called manually if needed.
   *
   * @param string $ipAddress
   *   The client IP address.
   */
  public function recordAttempt(string $ipAddress): void {
    // Rate limiting is handled automatically through transaction logging
    // This method is provided for completeness but typically not needed
  }

  /**
   * Cleans up old rate limiting data.
   *
   * @return int
   *   Number of old records cleaned up.
   */
  public function cleanup(): int {
    try {
      $cutoff = time() - self::CLEANUP_INTERVAL;
      
      // We don't actually delete transactions, just note that cleanup
      // is handled by TransactionLogger based on retention policy
      return 0;
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Gets rate limiting configuration for API responses.
   *
   * @return array
   *   Rate limiting configuration.
   */
  public function getConfiguration(): array {
    return [
      'enabled' => $this->isRateLimitingEnabled(),
      'max_attempts' => $this->getMaxAttempts(),
      'window_seconds' => $this->getWindow(),
      'window_description' => $this->formatWindow($this->getWindow()),
    ];
  }

  /**
   * Checks if an IP address is in a whitelist.
   *
   * @param string $ipAddress
   *   The IP address to check.
   *
   * @return bool
   *   TRUE if the IP is whitelisted.
   */
  public function isWhitelisted(string $ipAddress): bool {
    // Common local/private IP ranges that should not be rate limited
    $whitelistedRanges = [
      '127.0.0.0/8',    // Loopback
      '10.0.0.0/8',     // Private Class A
      '172.16.0.0/12',  // Private Class B
      '192.168.0.0/16', // Private Class C
      '::1/128',        // IPv6 loopback
    ];

    foreach ($whitelistedRanges as $range) {
      if ($this->ipInRange($ipAddress, $range)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Checks if rate limiting is enabled in configuration.
   *
   * @return bool
   *   TRUE if rate limiting is enabled.
   */
  private function isRateLimitingEnabled(): bool {
    return (bool) $this->configService->get('rate_limit_enabled', true);
  }

  /**
   * Gets the maximum attempts allowed per window.
   *
   * @return int
   *   Maximum attempts.
   */
  private function getMaxAttempts(): int {
    return (int) $this->configService->get('max_attempts_per_hour', self::DEFAULT_MAX_ATTEMPTS);
  }

  /**
   * Gets the rate limiting window in seconds.
   *
   * @return int
   *   Window duration in seconds.
   */
  private function getWindow(): int {
    // Currently fixed to 1 hour, but could be configurable
    return self::DEFAULT_WINDOW;
  }

  /**
   * Gets the number of recent attempts for an IP address.
   *
   * @param string $ipAddress
   *   The client IP address.
   *
   * @return int
   *   Number of attempts in the current window.
   */
  private function getRecentAttempts(string $ipAddress): int {
    if (!$this->database->schema()->tableExists(self::TABLE_NAME)) {
      return 0;
    }

    $windowStart = time() - $this->getWindow();
    
    $query = $this->database->select(self::TABLE_NAME, 't')
      ->condition('ip_address', $ipAddress)
      ->condition('created', $windowStart, '>');
    
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Gets the timestamp of the oldest attempt in the current window.
   *
   * @param string $ipAddress
   *   The client IP address.
   *
   * @return int|null
   *   Timestamp of oldest attempt or NULL if none found.
   */
  private function getOldestAttemptInWindow(string $ipAddress): ?int {
    if (!$this->database->schema()->tableExists(self::TABLE_NAME)) {
      return null;
    }

    $windowStart = time() - $this->getWindow();
    
    $result = $this->database->select(self::TABLE_NAME, 't')
      ->fields('t', ['created'])
      ->condition('ip_address', $ipAddress)
      ->condition('created', $windowStart, '>')
      ->orderBy('created', 'ASC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    
    return $result ? (int) $result : null;
  }

  /**
   * Formats a time window for human-readable display.
   *
   * @param int $seconds
   *   Window duration in seconds.
   *
   * @return string
   *   Human-readable window description.
   */
  private function formatWindow(int $seconds): string {
    if ($seconds >= 3600) {
      $hours = round($seconds / 3600, 1);
      return $hours == 1 ? '1 hour' : "{$hours} hours";
    }
    
    if ($seconds >= 60) {
      $minutes = round($seconds / 60, 1);
      return $minutes == 1 ? '1 minute' : "{$minutes} minutes";
    }
    
    return "{$seconds} seconds";
  }

  /**
   * Checks if an IP address is within a CIDR range.
   *
   * @param string $ip
   *   The IP address to check.
   * @param string $range
   *   The CIDR range.
   *
   * @return bool
   *   TRUE if the IP is in the range.
   */
  private function ipInRange(string $ip, string $range): bool {
    if (strpos($range, '/') === false) {
      return $ip === $range;
    }

    [$subnet, $bits] = explode('/', $range);
    
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      return $this->ipv4InRange($ip, $subnet, (int) $bits);
    }
    
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
      return $this->ipv6InRange($ip, $subnet, (int) $bits);
    }
    
    return false;
  }

  /**
   * Checks if an IPv4 address is within a CIDR range.
   *
   * @param string $ip
   *   IPv4 address.
   * @param string $subnet
   *   Subnet address.
   * @param int $bits
   *   CIDR bits.
   *
   * @return bool
   *   TRUE if in range.
   */
  private function ipv4InRange(string $ip, string $subnet, int $bits): bool {
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    
    return ($ip & $mask) === ($subnet & $mask);
  }

  /**
   * Checks if an IPv6 address is within a CIDR range.
   *
   * @param string $ip
   *   IPv6 address.
   * @param string $subnet
   *   Subnet address.
   * @param int $bits
   *   CIDR bits.
   *
   * @return bool
   *   TRUE if in range.
   */
  private function ipv6InRange(string $ip, string $subnet, int $bits): bool {
    $ip = inet_pton($ip);
    $subnet = inet_pton($subnet);
    
    if ($ip === false || $subnet === false) {
      return false;
    }
    
    $bytesToCheck = intval($bits / 8);
    $bitsInLastByte = $bits % 8;
    
    for ($i = 0; $i < $bytesToCheck; $i++) {
      if ($ip[$i] !== $subnet[$i]) {
        return false;
      }
    }
    
    if ($bitsInLastByte > 0) {
      $mask = 0xFF << (8 - $bitsInLastByte);
      return (ord($ip[$bytesToCheck]) & $mask) === (ord($subnet[$bytesToCheck]) & $mask);
    }
    
    return true;
  }
}