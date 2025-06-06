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
        'currency' => $paymentData['currency'] ?? $this->config->get('currency') ?? 'AUD',
        'merchantCode' => $this->config->get('merchant_code'),
        'orderId' => $paymentData['orderId'] ?? $this->generateOrderId(),
        'ipAddress' => $paymentData['ipAddress'] ?? null,
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
    catch (\Exception $e) {
      $this->logger->error('Payment processing failed: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $paymentData['orderId'] ?? 'unknown',
      ]);
      
      // Return failure result
      $result = PaymentResult::failure($e->getMessage());
      return $result->toArray();
    }
  }

  /**
   * Generate unique order ID.
   */
  public function generateOrderId(): string {
    return 'WF_' . time() . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
  }

  /**
   * Validate payment data.
   */
  private function validatePaymentData(array $data): void {
    $requiredFields = ['token', 'amount', 'currency'];
    
    foreach ($requiredFields as $field) {
      if (empty($data[$field])) {
        throw ValidationException::requiredField($field);
      }
    }

    // Validate amount
    if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
      throw ValidationException::invalidAmount((int) $data['amount'], 1, PHP_INT_MAX);
    }

    // Validate currency
    $supportedCurrencies = array_keys(ConfigurationService::getCurrencyOptions());
    if (!in_array($data['currency'], $supportedCurrencies)) {
      throw ValidationException::unsupportedCurrency($data['currency'], $supportedCurrencies);
    }

    // Validate token format (basic check)
    if (strlen($data['token']) < 10) {
      throw ValidationException::invalidToken('Token too short');
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
        'status' => $result->isSuccess() ? 'success' : 'failed',
        'ip_address' => $request->getIpAddress(),
        'created' => time(),
        'response_data' => json_encode($result->getRawResponse()),
      ];

      // Add transaction ID if successful
      if ($result->getTransactionId()) {
        $fields['transaction_id'] = $result->getTransactionId();
      }

      $this->database->insert('webform_securepay_transactions')
        ->fields($fields)
        ->execute();

      $this->logger->info('Transaction logged: {order_id} - {status}', [
        'order_id' => $request->getOrderId(),
        'status' => $result->isSuccess() ? 'success' : 'failed',
        'transaction_id' => $result->getTransactionId(),
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
}