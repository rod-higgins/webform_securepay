<?php

namespace Drupal\webform_securepay\Service;

use Drupal\webform_securepay\Exception\PaymentException;
use Drupal\webform_securepay\ValueObject\PaymentRequest;
use Drupal\webform_securepay\ValueObject\PaymentResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Interface for payment processor service.
 */
interface PaymentProcessorInterface {
  public function processPayment(array $data, Request $request): array;
}

/**
 * Core payment processing service.
 */
class PaymentProcessor implements PaymentProcessorInterface {

  public function __construct(
    private readonly SecurePayApiServiceInterface $apiService,
    private readonly ConfigurationService $configService,
    private readonly TransactionLogger $transactionLogger,
    private readonly PaymentValidator $validator,
    private readonly LoggerInterface $logger,
  ) {}

  public function processPayment(array $data, Request $request): array {
    try {
      $paymentRequest = $this->buildPaymentRequest($data, $request);
      $this->validator->validate($paymentRequest);
      
      $result = $this->apiService->processPayment($paymentRequest->toArray());
      $paymentResult = PaymentResult::fromApiResponse($result);
      
      $this->transactionLogger->log($paymentRequest, $paymentResult, $request);
      
      return $paymentResult->toArray();
    }
    catch (PaymentException $e) {
      $this->logger->error('Payment processing failed: {message}', [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
      ]);
      
      return [
        'success' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->getCode(),
      ];
    }
  }

  private function buildPaymentRequest(array $data, Request $request): PaymentRequest {
    return new PaymentRequest(
      token: $data['token'],
      amount: (int) $data['amount'],
      currency: $data['currency'] ?? $this->configService->get('currency', 'AUD'),
      merchantCode: $data['merchantCode'] ?? $this->configService->get('merchant_code'),
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
}