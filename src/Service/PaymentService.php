<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Database\Connection;
use Drupal\webform_securepay\Exception\ValidationException;
use Drupal\webform_securepay\ValueObject\PaymentRequest;
use Drupal\webform_securepay\ValueObject\PaymentResult;
use Psr\Log\LoggerInterface;

/**
 * Payment service for processing and logging.
 */
class PaymentService {

  // Validation constants
  private const MIN_AMOUNT = 1;
  private const MAX_AMOUNT = 999999999; // $9,999,999.99
  private const MIN_TOKEN_LENGTH = 10;
  private const MAX_TOKEN_LENGTH = 1000;
  private const TOKEN_PATTERN = '/^[a-zA-Z0-9_-]+$/';
  private const ORDER_ID_PREFIX = 'WF_';
  private const ORDER_ID_HASH_LENGTH = 8;
  
  // Database constants
  private const TABLE_TRANSACTIONS = 'webform_securepay_transactions';
  private const STATUS_SUCCESS = 'success';
  private const STATUS_FAILED = 'failed';

  public function __construct(
    private readonly SecurePayApiService $apiService,
    private readonly ConfigurationService $config,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Process payment.
   */
  public function processPayment(array $paymentData): array {
    // Validate input data
    $this->validatePaymentData($paymentData);

    try {
      // Create payment request
      $request = PaymentRequest::fromArray([
        'token' => $paymentData['token'],
        'amount' => (int) $paymentData['amount'],
        'currency' => $paymentData['currency'] ?? $this->config->get('currency') ?? ConfigurationService::CURRENCY_AUD,
        'merchantCode' => $this->config->get('merchant_code'),
        'orderId' => $paymentData['orderId'] ?? $this->generateOrderId(),
        'ipAddress' => $this->sanitizeIpAddress($paymentData['ipAddress'] ?? null),
      ]);

      // Process payment via API
      $apiResponse = $this->apiService->processPayment($request->toArray());
      
      // Create result object
      if ($apiResponse['success']) {
        $result = PaymentResult::success(
          $apiResponse['transaction_id'],
          $apiResponse['status'],
          $apiResponse['raw_response'] ?? []
        );
      } else {
        $result = PaymentResult::failure(
          $apiResponse['error'] ?? 'Payment failed',
          $apiResponse['raw_response'] ?? []
        );
      }
      
      // Log transaction
      $this->logTransaction($request, $result);
      
      return $result->toArray();
    }
    catch (ValidationException $e) {
      $this->logger->warning('Payment validation failed: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $paymentData['orderId'] ?? 'unknown',
      ]);
      
      $result = PaymentResult::failure($e->getMessage());
      return $result->toArray();
    }
    catch (\Exception $e) {
      $this->logger->error('Payment processing failed: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $paymentData['orderId'] ?? 'unknown',
        'trace' => $e->getTraceAsString(),
      ]);
      
      // Return generic error to avoid exposing internal details
      $result = PaymentResult::failure('Payment processing failed');
      return $result->toArray();
    }
  }

  /**
   * Generate unique order ID.
   */
  public function generateOrderId(): string {
    return self::ORDER_ID_PREFIX . time() . '_' . substr(hash('sha256', uniqid(mt_rand(), true)), 0, self::ORDER_ID_HASH_LENGTH);
  }

  /**
   * Get transaction by order ID.
   */
  public function getTransactionByOrderId(string $orderId): ?array {
    if (empty(trim($orderId))) {
      return null;
    }

    try {
      $query = $this->database->select(self::TABLE_TRANSACTIONS, 't')
        ->fields('t')
        ->condition('order_id', $this->sanitizeOrderId($orderId))
        ->range(0, 1);
      
      $result = $query->execute()->fetchAssoc();
      
      if ($result) {
        // Decode JSON response data
        if (!empty($result['response_data'])) {
          $result['response_data'] = json_decode($result['response_data'], true) ?: [];
        }
        return $result;
      }
      
      return null;
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
   * Get transactions by status.
   */
  public function getTransactionsByStatus(string $status, int $limit = 50): array {
    if (!in_array($status, [self::STATUS_SUCCESS, self::STATUS_FAILED], true)) {
      return [];
    }

    try {
      $query = $this->database->select(self::TABLE_TRANSACTIONS, 't')
        ->fields('t')
        ->condition('status', $status)
        ->orderBy('created', 'DESC')
        ->range(0, max(1, min($limit, 1000)));
      
      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
      
      // Decode JSON response data
      foreach ($results as &$result) {
        if (!empty($result['response_data'])) {
          $result['response_data'] = json_decode($result['response_data'], true) ?: [];
        }
      }
      
      return $results;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve transactions by status: {message}', [
        'message' => $e->getMessage(),
        'status' => $status,
      ]);
      return [];
    }
  }

  /**
   * Validate payment data.
   */
  private function validatePaymentData(array $data): void {
    $requiredFields = ['token', 'amount', 'currency'];
    
    foreach ($requiredFields as $field) {
      if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
        throw ValidationException::requiredField($field);
      }
    }

    // Validate amount
    $amount = $data['amount'];
    if (!is_numeric($amount)) {
      throw ValidationException::invalidAmount(0, self::MIN_AMOUNT, self::MAX_AMOUNT);
    }

    $amount = (int) $amount;
    if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
      throw ValidationException::invalidAmount($amount, self::MIN_AMOUNT, self::MAX_AMOUNT);
    }

    // Validate currency
    $currency = strtoupper(trim($data['currency']));
    if (!$this->config->isValidCurrency($currency)) {
      throw ValidationException::unsupportedCurrency($currency, ConfigurationService::getValidCurrencies());
    }

    // Validate token format
    $token = trim($data['token']);
    if (strlen($token) < self::MIN_TOKEN_LENGTH || strlen($token) > self::MAX_TOKEN_LENGTH) {
      throw ValidationException::invalidToken('Token length invalid');
    }

    if (!preg_match(self::TOKEN_PATTERN, $token)) {
      throw ValidationException::invalidToken('Token contains invalid characters');
    }

    // Validate merchant code if not configured
    if (empty($this->config->get('merchant_code'))) {
      throw ValidationException::missingMerchantCode();
    }
  }

  /**
   * Log transaction to database.
   */
  private function logTransaction(PaymentRequest $request, PaymentResult $result): void {
    try {
      $fields = [
        'order_id' => $request->getOrderId(),
        'amount' => $request->getAmount(),
        'currency' => $request->getCurrency(),
        'status' => $result->isSuccess() ? self::STATUS_SUCCESS : self::STATUS_FAILED,
        'ip_address' => $this->truncateIpAddress($request->getIpAddress()),
        'created' => time(),
        'response_data' => json_encode($this->sanitizeResponseData($result->getRawResponse())),
      ];

      // Add transaction ID if successful
      if ($result->getTransactionId()) {
        $fields['transaction_id'] = $this->sanitizeTransactionId($result->getTransactionId());
      }

      $this->database->insert(self::TABLE_TRANSACTIONS)
        ->fields($fields)
        ->execute();

      $this->logger->info('Transaction logged: {order_id} - {status}', [
        'order_id' => $request->getOrderId(),
        'status' => $result->isSuccess() ? self::STATUS_SUCCESS : self::STATUS_FAILED,
        'transaction_id' => $result->getTransactionId(),
        'amount' => $request->getAmount(),
        'currency' => $request->getCurrency(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to log transaction: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $request->getOrderId(),
      ]);
      // Don't throw here - logging failure shouldn't break payment
    }
  }

  /**
   * Sanitize IP address for database storage.
   */
  private function sanitizeIpAddress(?string $ipAddress): ?string {
    if ($ipAddress === null) {
      return null;
    }
    
    $ip = trim($ipAddress);
    
    // Validate IP address
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
      return $ip;
    }
    
    return null;
  }

  /**
   * Truncate IP address for database field limits.
   */
  private function truncateIpAddress(?string $ipAddress): ?string {
    if ($ipAddress === null) {
      return null;
    }
    
    // IPv6 addresses can be up to 45 characters, which matches our field size
    return substr($ipAddress, 0, 45);
  }

  /**
   * Sanitize order ID.
   */
  private function sanitizeOrderId(string $orderId): string {
    return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($orderId)), 0, 255);
  }

  /**
   * Sanitize transaction ID.
   */
  private function sanitizeTransactionId(string $transactionId): string {
    return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($transactionId)), 0, 255);
  }

  /**
   * Sanitize response data for logging.
   */
  private function sanitizeResponseData(array $response): array {
    // Remove sensitive data that should never be logged
    $sanitized = $response;
    $sensitiveFields = [
      'cardNumber', 'cvv', 'expiryDate', 'cardholderName',
      'token', 'clientSecret', 'password', 'pin'
    ];
    
    foreach ($sensitiveFields as $field) {
      unset($sanitized[$field]);
    }
    
    // Recursively sanitize nested arrays
    array_walk_recursive($sanitized, function (&$value, $key) use ($sensitiveFields) {
      if (in_array($key, $sensitiveFields, true)) {
        $value = '[REDACTED]';
      }
    });
    
    return $sanitized;
  }
}