<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform_securepay\Exception\PaymentException;
use Drupal\webform_securepay\Exception\ValidationException;
use Drupal\webform_securepay\ValueObject\PaymentRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Consolidated payment service handling processing, validation, logging, and notifications.
 */
class PaymentService {

  // Rate limiting constants
  private const RATE_LIMIT_TABLE = 'webform_securepay_rate_limits';
  private const DEFAULT_RATE_LIMIT = 50; // requests per hour
  private const RATE_LIMIT_WINDOW = 3600; // 1 hour in seconds

  private SecurePayApiServiceInterface $apiService;
  private ConfigurationService $configService;
  private Connection $database;
  private MailManagerInterface $mailManager;
  private AccountInterface $currentUser;
  private LoggerInterface $logger;

  public function __construct(
    SecurePayApiServiceInterface $apiService,
    ConfigurationService $configService,
    Connection $database,
    MailManagerInterface $mailManager,
    AccountInterface $currentUser,
    LoggerInterface $logger
  ) {
    $this->apiService = $apiService;
    $this->configService = $configService;
    $this->database = $database;
    $this->mailManager = $mailManager;
    $this->currentUser = $currentUser;
    $this->logger = $logger;
  }

  /**
   * Process payment with full validation and logging.
   */
  public function processPayment(array $paymentData, Request $request): array {
    // Validate request data
    $this->validatePaymentData($paymentData);
    
    // Check rate limiting
    $ipAddress = $request->getClientIp();
    if ($this->isRateLimited($ipAddress)) {
      throw new PaymentException('Rate limit exceeded. Please try again later.');
    }

    // Create payment request object
    $paymentRequest = PaymentRequest::fromArray([
      'token' => $paymentData['token'],
      'amount' => (int) $paymentData['amount'],
      'currency' => $paymentData['currency'] ?? 'AUD',
      'merchantCode' => $paymentData['merchantCode'] ?? $this->configService->get(ConfigurationService::MERCHANT_CODE),
      'orderId' => $paymentData['orderId'] ?? $this->generateOrderId(),
      'ipAddress' => $ipAddress,
      'userAgent' => $request->headers->get('User-Agent'),
      'dccQuote' => $paymentData['dccQuote'] ?? null,
      'threeDSResult' => $paymentData['threeDSResult'] ?? null,
    ]);

    // Record rate limiting attempt
    $this->recordRateLimitAttempt($ipAddress);

    try {
      // Process payment through API
      $result = $this->apiService->processPayment($paymentRequest->toArray());
      
      // Log successful transaction
      $transactionId = $this->logTransaction($paymentRequest, $result, 'success');
      
      // Send success notifications
      $this->sendPaymentNotification('success', $result, $paymentRequest);
      
      return [
        'success' => true,
        'transaction_id' => $transactionId,
        'status' => $result['status'] ?? 'completed',
        'amount' => $paymentRequest->getAmount(),
        'currency' => $paymentRequest->getCurrency(),
        'gateway_response' => $result,
      ];
    }
    catch (\Exception $e) {
      // Log failed transaction
      $this->logTransaction($paymentRequest, ['error' => $e->getMessage()], 'failed');
      
      // Send failure notifications
      $this->sendPaymentNotification('failure', ['error' => $e->getMessage()], $paymentRequest);
      
      throw $e;
    }
  }

  /**
   * Check if IP address is rate limited.
   */
  public function isRateLimited(string $ipAddress): bool {
    if (!$this->configService->get(ConfigurationService::RATE_LIMIT_ENABLED)) {
      return false;
    }

    $maxAttempts = $this->configService->get(ConfigurationService::MAX_ATTEMPTS_PER_HOUR) ?? self::DEFAULT_RATE_LIMIT;
    $windowStart = time() - self::RATE_LIMIT_WINDOW;

    try {
      $attemptCount = $this->database->select(self::RATE_LIMIT_TABLE, 'r')
        ->condition('ip_address', $ipAddress)
        ->condition('timestamp', $windowStart, '>')
        ->countQuery()
        ->execute()
        ->fetchField();

      return $attemptCount >= $maxAttempts;
    }
    catch (\Exception $e) {
      $this->logger->warning('Rate limit check failed: {message}', [
        'message' => $e->getMessage(),
        'ip' => $ipAddress,
      ]);
      return false;
    }
  }

  /**
   * Get transaction by order ID.
   */
  public function getTransactionByOrderId(string $orderId): ?array {
    try {
      $result = $this->database->select('webform_securepay_transactions', 't')
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
   * Get transaction statistics.
   */
  public function getTransactionStats(int $days = 30): array {
    $cutoff = time() - ($days * 24 * 60 * 60);

    try {
      $query = $this->database->select('webform_securepay_transactions', 't')
        ->condition('created', $cutoff, '>');

      $total = $query->countQuery()->execute()->fetchField();
      
      $successful = $this->database->select('webform_securepay_transactions', 't')
        ->condition('created', $cutoff, '>')
        ->condition('status', 'success')
        ->countQuery()
        ->execute()
        ->fetchField();

      $successRate = $total > 0 ? round(($successful / $total) * 100, 2) : 0;

      return [
        'total' => (int) $total,
        'successful' => (int) $successful,
        'failed' => (int) ($total - $successful),
        'success_rate' => $successRate,
        'period_days' => $days,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get transaction stats: {message}', [
        'message' => $e->getMessage(),
      ]);
      
      return [
        'total' => 0,
        'successful' => 0,
        'failed' => 0,
        'success_rate' => 0,
        'period_days' => $days,
      ];
    }
  }

  /**
   * Validate payment data.
   */
  private function validatePaymentData(array $data): void {
    $required = ['token', 'amount'];
    foreach ($required as $field) {
      if (empty($data[$field])) {
        throw ValidationException::requiredField($field);
      }
    }

    $amount = (int) $data['amount'];
    if ($amount <= 0 || $amount > 99999999) {
      throw ValidationException::invalidAmount($amount, 1, 99999999);
    }

    if (isset($data['currency'])) {
      $supportedCurrencies = ['AUD', 'USD', 'EUR', 'GBP', 'NZD', 'CAD', 'JPY', 'SGD'];
      if (!in_array($data['currency'], $supportedCurrencies, true)) {
        throw ValidationException::unsupportedCurrency($data['currency'], $supportedCurrencies);
      }
    }
  }

  /**
   * Generate unique order ID.
   */
  private function generateOrderId(): string {
    $prefix = $this->configService->get(ConfigurationService::ORDER_ID_PREFIX) ?? 'WF_';
    $timestamp = time();
    $random = substr(md5(uniqid()), 0, 8);
    
    return $prefix . $timestamp . '_' . $random;
  }

  /**
   * Record rate limiting attempt.
   */
  private function recordRateLimitAttempt(string $ipAddress): void {
    if (!$this->configService->get(ConfigurationService::RATE_LIMIT_ENABLED)) {
      return;
    }

    try {
      $this->database->insert(self::RATE_LIMIT_TABLE)
        ->fields([
          'ip_address' => $ipAddress,
          'timestamp' => time(),
          'user_id' => $this->currentUser->id(),
        ])
        ->execute();

      // Clean up old rate limit records
      $this->cleanupRateLimitRecords();
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to record rate limit attempt: {message}', [
        'message' => $e->getMessage(),
        'ip' => $ipAddress,
      ]);
    }
  }

  /**
   * Log transaction to database.
   */
  private function logTransaction(PaymentRequest $request, array $result, string $status): ?string {
    if (!$this->configService->get(ConfigurationService::LOG_TRANSACTIONS)) {
      return null;
    }

    try {
      $transactionId = $this->database->insert('webform_securepay_transactions')
        ->fields([
          'order_id' => $request->getOrderId(),
          'transaction_id' => $result['transactionId'] ?? null,
          'amount' => $request->getAmount(),
          'currency' => $request->getCurrency(),
          'status' => $status,
          'gateway_response_code' => $result['responseCode'] ?? null,
          'gateway_response_message' => $result['responseMessage'] ?? null,
          'ip_address' => $request->getIpAddress(),
          'user_agent' => $request->getUserAgent(),
          'created' => time(),
          'updated' => time(),
          'merchant_code' => $request->getMerchantCode(),
          'response_data' => json_encode($result),
        ])
        ->execute();

      return (string) $transactionId;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to log transaction: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $request->getOrderId(),
      ]);
      return null;
    }
  }

  /**
   * Send payment notification emails.
   */
  private function sendPaymentNotification(string $type, array $result, PaymentRequest $request): void {
    if (!$this->configService->get(ConfigurationService::EMAIL_NOTIFICATIONS)) {
      return;
    }

    $notificationEmail = $this->configService->get(ConfigurationService::NOTIFICATION_EMAIL);
    if (empty($notificationEmail)) {
      return;
    }

    try {
      $mailKey = $type === 'success' ? 'payment_success' : 'payment_failure';
      
      $this->mailManager->mail(
        'webform_securepay',
        $mailKey,
        $notificationEmail,
        'en',
        [
          'result' => $result,
          'request' => $request,
          'subject' => $this->getNotificationSubject($type, $request),
          'body' => $this->getNotificationBody($type, $result, $request),
        ]
      );
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to send payment notification: {message}', [
        'message' => $e->getMessage(),
        'type' => $type,
        'order_id' => $request->getOrderId(),
      ]);
    }
  }

  /**
   * Clean up old rate limit records.
   */
  private function cleanupRateLimitRecords(): void {
    try {
      $cutoff = time() - (self::RATE_LIMIT_WINDOW * 2); // Keep 2x window for safety
      
      $this->database->delete(self::RATE_LIMIT_TABLE)
        ->condition('timestamp', $cutoff, '<')
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to cleanup rate limit records: {message}', [
        'message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Get notification email subject.
   */
  private function getNotificationSubject(string $type, PaymentRequest $request): string {
    return $type === 'success' 
      ? "Payment Successful - {$request->getOrderId()}"
      : "Payment Failed - {$request->getOrderId()}";
  }

  /**
   * Get notification email body.
   */
  private function getNotificationBody(string $type, array $result, PaymentRequest $request): array {
    $body = [
      "Payment {$type} notification:",
      '',
      "Order ID: {$request->getOrderId()}",
      "Amount: {$request->getFormattedAmount()}",
      "Time: " . date('Y-m-d H:i:s'),
    ];

    if ($type === 'success') {
      $body[] = "Transaction ID: " . ($result['transactionId'] ?? 'N/A');
    } else {
      $body[] = "Error: " . ($result['error'] ?? 'Unknown error');
    }

    return $body;
  }
}