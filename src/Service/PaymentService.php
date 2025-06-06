<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Database\Connection;
use Drupal\webform_securepay\ValueObject\PaymentRequest;
use Drupal\webform_securepay\ValueObject\PaymentResult;
use Psr\Log\LoggerInterface;

/**
 * Payment service for processing and logging.
 */
class PaymentService {

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
    // Create payment request
    $request = PaymentRequest::fromArray([
      'token' => $paymentData['token'],
      'amount' => (int) $paymentData['amount'],
      'currency' => $paymentData['currency'] ?? $this->config->get('currency') ?? 'AUD',
      'merchantCode' => $this->config->get('merchant_code'),
      'orderId' => $this->generateOrderId(),
      'ipAddress' => $paymentData['ipAddress'] ?? null,
    ]);

    // Process payment
    $result = $this->apiService->processPayment($request);
    
    // Log transaction
    $this->logTransaction($request, $result);
    
    return $result->toArray();
  }

  /**
   * Generate unique order ID.
   */
  public function generateOrderId(): string {
    return 'WF_' . time() . '_' . substr(md5(uniqid()), 0, 8);
  }

  /**
   * Log transaction to database.
   */
  private function logTransaction(PaymentRequest $request, PaymentResult $result): void {
    try {
      $this->database->insert('webform_securepay_transactions')
        ->fields([
          'order_id' => $request->getOrderId(),
          'transaction_id' => $result->getTransactionId(),
          'amount' => $request->getAmount(),
          'currency' => $request->getCurrency(),
          'status' => $result->isSuccess() ? 'success' : 'failed',
          'ip_address' => $request->getIpAddress(),
          'created' => time(),
          'response_data' => json_encode($result->getRawResponse()),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to log transaction: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $request->getOrderId(),
      ]);
    }
  }
}