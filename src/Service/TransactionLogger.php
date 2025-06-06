<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Database\Connection;
use Drupal\webform_securepay\ValueObject\PaymentRequest;
use Drupal\webform_securepay\ValueObject\PaymentResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Logs payment transactions to the database.
 */
class TransactionLogger {

  /**
   * Database table name for transactions.
   */
  private const TABLE_NAME = 'webform_securepay_transactions';

  /**
   * Maximum length for stored user agent strings.
   */
  private const MAX_USER_AGENT_LENGTH = 500;

  /**
   * Constructs a TransactionLogger.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\webform_securepay\Service\ConfigurationService $configService
   *   The configuration service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ConfigurationService $configService,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Logs a payment transaction.
   *
   * @param \Drupal\webform_securepay\ValueObject\PaymentRequest $request
   *   The payment request.
   * @param \Drupal\webform_securepay\ValueObject\PaymentResult $result
   *   The payment result.
   * @param \Symfony\Component\HttpFoundation\Request $httpRequest
   *   The HTTP request.
   */
  public function log(PaymentRequest $request, PaymentResult $result, Request $httpRequest): void {
    if (!$this->isLoggingEnabled()) {
      return;
    }

    try {
      $this->insertTransaction($request, $result, $httpRequest);
      
      if ($this->configService->get('debug_mode')) {
        $this->logger->debug('Transaction logged successfully: {order_id}', [
          'order_id' => $request->orderId,
          'status' => $result->status,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to log transaction: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $request->orderId,
        'exception' => $e,
      ]);
    }
  }

  /**
   * Updates a transaction with submission information.
   *
   * @param string $orderId
   *   The order ID.
   * @param int $submissionId
   *   The webform submission ID.
   * @param string $webformId
   *   The webform ID.
   */
  public function updateWithSubmission(string $orderId, int $submissionId, string $webformId): void {
    if (!$this->isLoggingEnabled()) {
      return;
    }

    try {
      $this->database->update(self::TABLE_NAME)
        ->fields([
          'submission_id' => $submissionId,
          'webform_id' => $webformId,
          'updated' => time(),
        ])
        ->condition('order_id', $orderId)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update transaction with submission: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $orderId,
        'submission_id' => $submissionId,
      ]);
    }
  }

  /**
   * Retrieves a transaction by order ID.
   *
   * @param string $orderId
   *   The order ID.
   *
   * @return array|null
   *   Transaction data or NULL if not found.
   */
  public function getByOrderId(string $orderId): ?array {
    try {
      $result = $this->database->select(self::TABLE_NAME, 't')
        ->fields('t')
        ->condition('order_id', $orderId)
        ->execute()
        ->fetchAssoc();

      return $result ?: null;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve transaction: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $orderId,
      ]);
      return null;
    }
  }

  /**
   * Gets transaction statistics.
   *
   * @param int $days
   *   Number of days to look back.
   *
   * @return array
   *   Statistics array with counts and totals.
   */
  public function getStatistics(int $days = 30): array {
    if (!$this->isLoggingEnabled()) {
      return [
        'total_transactions' => 0,
        'successful_transactions' => 0,
        'failed_transactions' => 0,
        'total_amount' => 0,
        'success_rate' => 0,
      ];
    }

    try {
      $cutoff = time() - ($days * 24 * 60 * 60);
      
      $query = $this->database->select(self::TABLE_NAME, 't')
        ->condition('created', $cutoff, '>')
        ->fields('t', ['status', 'amount']);
      
      $results = $query->execute()->fetchAll();
      
      $stats = [
        'total_transactions' => count($results),
        'successful_transactions' => 0,
        'failed_transactions' => 0,
        'total_amount' => 0,
      ];
      
      foreach ($results as $row) {
        if ($row->status === 'paid') {
          $stats['successful_transactions']++;
          $stats['total_amount'] += (int) $row->amount;
        } else {
          $stats['failed_transactions']++;
        }
      }
      
      $stats['success_rate'] = $stats['total_transactions'] > 0 
        ? round(($stats['successful_transactions'] / $stats['total_transactions']) * 100, 2)
        : 0;
      
      return $stats;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get transaction statistics: {message}', [
        'message' => $e->getMessage(),
      ]);
      return [
        'total_transactions' => 0,
        'successful_transactions' => 0,
        'failed_transactions' => 0,
        'total_amount' => 0,
        'success_rate' => 0,
      ];
    }
  }

  /**
   * Cleans up old transactions based on retention policy.
   *
   * @param int $retentionDays
   *   Number of days to retain transactions.
   *
   * @return int
   *   Number of transactions deleted.
   */
  public function cleanup(int $retentionDays): int {
    if (!$this->isLoggingEnabled() || $retentionDays <= 0) {
      return 0;
    }

    try {
      $cutoff = time() - ($retentionDays * 24 * 60 * 60);
      
      $deleted = $this->database->delete(self::TABLE_NAME)
        ->condition('created', $cutoff, '<')
        ->execute();
      
      if ($deleted > 0) {
        $this->logger->info('Cleaned up {count} old transactions older than {days} days', [
          'count' => $deleted,
          'days' => $retentionDays,
        ]);
      }
      
      return $deleted;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cleanup old transactions: {message}', [
        'message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Checks if transaction logging is enabled.
   *
   * @return bool
   *   TRUE if logging is enabled.
   */
  private function isLoggingEnabled(): bool {
    return (bool) $this->configService->get('log_transactions');
  }

  /**
   * Inserts a transaction record into the database.
   *
   * @param \Drupal\webform_securepay\ValueObject\PaymentRequest $request
   *   The payment request.
   * @param \Drupal\webform_securepay\ValueObject\PaymentResult $result
   *   The payment result.
   * @param \Symfony\Component\HttpFoundation\Request $httpRequest
   *   The HTTP request.
   */
  private function insertTransaction(PaymentRequest $request, PaymentResult $result, Request $httpRequest): void {
    $userAgent = $httpRequest->headers->get('User-Agent', '');
    if (strlen($userAgent) > self::MAX_USER_AGENT_LENGTH) {
      $userAgent = substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);
    }

    $fields = [
      'order_id' => $request->orderId,
      'transaction_id' => $result->transactionId,
      'amount' => $request->amount,
      'currency' => $request->currency,
      'status' => $result->status,
      'gateway_response_code' => $result->gatewayResponseCode,
      'gateway_response_message' => $result->gatewayResponseMessage,
      'ip_address' => $request->ipAddress,
      'user_agent' => $userAgent,
      'raw_response' => $this->encodeRawResponse($result->rawResponse),
      'created' => time(),
      'updated' => time(),
    ];

    // Remove null values to avoid database issues
    $fields = array_filter($fields, fn($value) => $value !== null);

    $this->database->insert(self::TABLE_NAME)
      ->fields($fields)
      ->execute();
  }

  /**
   * Safely encodes raw response data for storage.
   *
   * @param array|null $rawResponse
   *   The raw API response.
   *
   * @return string|null
   *   JSON-encoded response or NULL.
   */
  private function encodeRawResponse(?array $rawResponse): ?string {
    if (empty($rawResponse)) {
      return null;
    }

    try {
      // Remove sensitive data before storing
      $sanitized = $this->sanitizeResponse($rawResponse);
      return json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to encode raw response: {message}', [
        'message' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * Removes sensitive data from API responses before storage.
   *
   * @param array $response
   *   The raw API response.
   *
   * @return array
   *   Sanitized response.
   */
  private function sanitizeResponse(array $response): array {
    // Remove potentially sensitive fields
    $sensitiveFields = [
      'token', 'cardToken', 'clientSecret', 'apiKey', 'password',
      'cardNumber', 'cvv', 'securityCode', 'accountNumber'
    ];

    foreach ($sensitiveFields as $field) {
      if (isset($response[$field])) {
        $response[$field] = '[REDACTED]';
      }
    }

    // Recursively sanitize nested arrays
    foreach ($response as $key => $value) {
      if (is_array($value)) {
        $response[$key] = $this->sanitizeResponse($value);
      }
    }

    return $response;
  }
}