<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform_securepay\Exception\PaymentException;
use Drupal\webform_securepay\Exception\ValidationException;
use Drupal\webform_securepay\ValueObject\PaymentRequest;
use Drupal\webform_securepay\ValueObject\PaymentResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Consolidated payment service that handles processing, validation, logging, and notifications.
 */
class PaymentService {

  private const MIN_AMOUNT = 1;
  private const MAX_AMOUNT = 99999999;
  private const SUPPORTED_CURRENCIES = ['AUD', 'USD', 'EUR', 'GBP', 'NZD', 'CAD', 'JPY', 'SGD'];
  private const SUPPORTED_CARD_TYPES = ['visa', 'mastercard', 'amex', 'diners'];

  public function __construct(
    private readonly SecurePayApiServiceInterface $apiService,
    private readonly ConfigurationService $configService,
    private readonly Connection $database,
    private readonly MailManagerInterface $mailManager,
    private readonly AccountInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Process a complete payment transaction.
   */
  public function processPayment(array $data, Request $request): array {
    try {
      $paymentRequest = $this->buildPaymentRequest($data, $request);
      $this->validatePaymentRequest($paymentRequest);
      
      $result = $this->apiService->processPayment($paymentRequest->toArray());
      $paymentResult = PaymentResult::fromApiResponse($result);
      
      $this->logTransaction($paymentRequest, $paymentResult, $request);
      $this->sendNotification($paymentResult, $this->buildNotificationContext($request));
      
      return $paymentResult->toArray();
    }
    catch (PaymentException | ValidationException $e) {
      $this->logger->error('Payment processing failed: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
      ]);
      
      return PaymentResult::failure($e->getMessage(), (string) $e->getCode())->toArray();
    }
  }

  /**
   * Validate payment request against business rules.
   */
  public function validatePaymentRequest(PaymentRequest $request): void {
    // Amount validation
    if ($request->amount < self::MIN_AMOUNT || $request->amount > self::MAX_AMOUNT) {
      throw new ValidationException("Amount must be between " . self::MIN_AMOUNT . " and " . self::MAX_AMOUNT . " cents");
    }

    // Currency validation
    if (!in_array($request->currency, self::SUPPORTED_CURRENCIES, true)) {
      throw new ValidationException("Unsupported currency: {$request->currency}");
    }

    // Token validation
    if (empty(trim($request->token)) || strlen($request->token) < 10) {
      throw new ValidationException("Invalid payment token");
    }

    // Merchant code validation
    if (empty(trim($request->merchantCode))) {
      throw new ValidationException("Merchant code is required");
    }
  }

  /**
   * Check if IP address is rate limited.
   */
  public function isRateLimited(string $ipAddress): bool {
    if (!$this->configService->get('rate_limit_enabled')) {
      return false;
    }

    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
      return true; // Invalid IP, consider rate limited
    }

    try {
      $maxAttempts = $this->configService->get('max_attempts_per_hour', 50);
      $windowStart = time() - 3600; // 1 hour

      $attempts = $this->database->select('webform_securepay_transactions', 't')
        ->condition('ip_address', $ipAddress)
        ->condition('created', $windowStart, '>')
        ->countQuery()
        ->execute()
        ->fetchField();

      return $attempts >= $maxAttempts;
    }
    catch (\Exception) {
      return false; // Fail open on database errors
    }
  }

  /**
   * Get transaction statistics for reporting.
   */
  public function getTransactionStats(int $days = 30): array {
    try {
      $cutoff = time() - ($days * 24 * 60 * 60);
      
      $results = $this->database->select('webform_securepay_transactions', 't')
        ->condition('created', $cutoff, '>')
        ->fields('t', ['status', 'amount'])
        ->execute()
        ->fetchAll();
      
      $stats = [
        'total' => count($results),
        'successful' => 0,
        'failed' => 0,
        'total_amount' => 0,
      ];
      
      foreach ($results as $row) {
        if ($row->status === 'paid') {
          $stats['successful']++;
          $stats['total_amount'] += (int) $row->amount;
        } else {
          $stats['failed']++;
        }
      }
      
      $stats['success_rate'] = $stats['total'] > 0 
        ? round(($stats['successful'] / $stats['total']) * 100, 2)
        : 0;
      
      return $stats;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get transaction statistics: {message}', [
        'message' => $e->getMessage(),
      ]);
      return ['total' => 0, 'successful' => 0, 'failed' => 0, 'total_amount' => 0, 'success_rate' => 0];
    }
  }

  private function buildPaymentRequest(array $data, Request $request): PaymentRequest {
    return new PaymentRequest(
      token: $data['token'] ?? '',
      amount: (int) ($data['amount'] ?? 0),
      currency: $data['currency'] ?? $this->configService->get('currency', 'AUD'),
      merchantCode: $data['merchantCode'] ?? $this->configService->get('merchant_code', ''),
      orderId: $this->generateOrderId(),
      ipAddress: $request->getClientIp(),
      userAgent: $request->headers->get('User-Agent'),
      dccQuote: $data['dccQuote'] ?? null,
      threeDSResult: $data['threeDSResult'] ?? null,
    );
  }

  private function generateOrderId(): string {
    $prefix = $this->configService->get('order_id_prefix', 'WF_');
    return $prefix . time() . '_' . substr(uniqid(), -6);
  }

  private function logTransaction(PaymentRequest $request, PaymentResult $result, Request $httpRequest): void {
    if (!$this->configService->get('log_transactions')) {
      return;
    }

    try {
      $this->database->insert('webform_securepay_transactions')
        ->fields([
          'order_id' => $request->orderId,
          'transaction_id' => $result->transactionId,
          'amount' => $request->amount,
          'currency' => $request->currency,
          'status' => $result->status,
          'gateway_response_code' => $result->gatewayResponseCode,
          'gateway_response_message' => $result->gatewayResponseMessage,
          'ip_address' => $request->ipAddress,
          'user_agent' => substr($httpRequest->headers->get('User-Agent', ''), 0, 500),
          'raw_response' => $result->rawResponse ? json_encode($result->rawResponse) : null,
          'created' => time(),
          'updated' => time(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to log transaction: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $request->orderId,
      ]);
    }
  }

  private function sendNotification(PaymentResult $result, array $context): void {
    if (!$this->configService->get('email_notifications')) {
      return;
    }

    $email = $this->configService->get('notification_email');
    if (empty($email)) {
      return;
    }

    try {
      $key = $result->success ? 'payment_success' : 'payment_failure';
      $subject = $result->success 
        ? "Payment Successful - {$result->transactionId}"
        : "Payment Failed - {$result->orderId}";

      $params = [
        'subject' => $subject,
        'result' => $result,
        'context' => $context,
      ];

      $this->mailManager->mail('webform_securepay', $key, $email, 'en', $params);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send notification: {message}', [
        'message' => $e->getMessage(),
        'transaction_id' => $result->transactionId,
      ]);
    }
  }

  private function buildNotificationContext(Request $request): array {
    return [
      'ip_address' => $request->getClientIp(),
      'user_agent' => $request->headers->get('User-Agent'),
      'timestamp' => time(),
    ];
  }
}