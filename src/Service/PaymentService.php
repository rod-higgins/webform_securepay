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
      if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
        throw ValidationException::requiredField($field);
      }
    }

    // Validate amount
    $amount = $data['amount'];
    if (!is_numeric($amount) || (int)$amount <= 0 || (int)$amount > 999999999) {
      throw ValidationException::invalidAmount((int)$amount, 1, 999999999);
    }

    // Validate currency
    $currency = strtoupper(trim($data['currency']));
    $supportedCurrencies = array_keys(ConfigurationService::getCurrencyOptions());
    if (!in_array($currency, $supportedCurrencies)) {
      throw ValidationException::unsupportedCurrency($currency, $supportedCurrencies);
    }

    // Validate token format
    $token = trim($data['token']);
    if (strlen($token) < 10 || !preg_match('/^[a-zA-Z0-9_-]+$/', $token)) {
      throw ValidationException::invalidToken('Invalid token format');
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