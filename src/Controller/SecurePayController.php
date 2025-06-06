<?php

namespace Drupal\webform_securepay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for SecurePay operations.
 */
class SecurePayController extends ControllerBase implements ContainerInjectionInterface {

  // HTTP status codes
  private const HTTP_OK = 200;
  private const HTTP_BAD_REQUEST = 400;
  private const HTTP_FORBIDDEN = 403;
  private const HTTP_NOT_FOUND = 404;
  private const HTTP_INTERNAL_ERROR = 500;

  // Required payment fields
  private const REQUIRED_PAYMENT_FIELDS = ['token', 'amount'];

  /**
   * The SecurePay API service.
   */
  protected SecurePayApiServiceInterface $securePayApi;

  /**
   * The configuration service.
   */
  protected ConfigurationService $configService;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a SecurePayController object.
   */
  public function __construct(
    SecurePayApiServiceInterface $securepay_api,
    ConfigurationService $config_service,
    LoggerInterface $logger
  ) {
    $this->securePayApi = $securepay_api;
    $this->configService = $config_service;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('webform_securepay.api'),
      $container->get('webform_securepay.configuration'),
      $container->get('logger.factory')->get('webform_securepay')
    );
  }

  /**
   * Handle payment callback from JavaScript.
   */
  public function paymentCallback(Request $request): JsonResponse {
    try {
      $data = $this->parseJsonRequest($request);
      $this->validatePaymentData($data);
      
      $payment_data = $this->preparePaymentData($data, $request);
      
      // Process fraud check if enabled
      if (!empty($data['fraud_check_enabled'])) {
        $fraud_result = $this->processFraudCheck($payment_data);
        if (!$fraud_result['success']) {
          return $this->createErrorResponse('Payment blocked by fraud detection', [
            'fraud_result' => $fraud_result,
          ]);
        }
        $payment_data['fraud_check_details'] = $fraud_result['details'];
      }
      
      $result = $this->securePayApi->processPayment($payment_data);
      
      $this->logTransaction($payment_data, $result);
      $this->sendNotifications($result);
      
      return new JsonResponse($result);
    }
    catch (\InvalidArgumentException $e) {
      return $this->createErrorResponse($e->getMessage(), [], self::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment callback error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return $this->createErrorResponse('Payment processing failed', [], self::HTTP_INTERNAL_ERROR);
    }
  }

  /**
   * Handle webhook notifications from SecurePay.
   */
  public function webhook(Request $request): Response {
    if (!$this->configService->get('webhook_enabled')) {
      return new Response('Webhook not enabled', self::HTTP_NOT_FOUND);
    }
    
    try {
      $this->verifyWebhookSignature($request);
      $data = $this->parseJsonRequest($request);
      $this->processWebhookEvent($data);
      
      return new Response('OK', self::HTTP_OK);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('Invalid webhook: @message', ['@message' => $e->getMessage()]);
      return new Response($e->getMessage(), self::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new Response('Processing failed', self::HTTP_INTERNAL_ERROR);
    }
  }

  /**
   * Test connection to SecurePay API.
   */
  public function testConnection(): Response {
    try {
      $success = $this->securePayApi->testConnection();
      $message = $success 
        ? $this->t('SecurePay connection test successful.')
        : $this->t('SecurePay connection test failed.');
      
      $this->messenger()->addMessage($message, $success ? 'status' : 'error');
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Connection test failed: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
    
    return $this->redirect('webform_securepay.admin_settings');
  }

  /**
   * Initiate payment order endpoint.
   */
  public function initiatePaymentOrder(Request $request): JsonResponse {
    try {
      $data = $this->parseJsonRequest($request);
      
      $amount = $data['amount'] ?? 0;
      $order_type = $data['order_type'] ?? 'DYNAMIC_CURRENCY_CONVERSION';
      $order_reference = $data['order_reference'] ?? NULL;
      
      if ($amount <= 0) {
        return $this->createErrorResponse('Invalid amount', [], self::HTTP_BAD_REQUEST);
      }
      
      $result = $this->securePayApi->initiatePaymentOrder($amount, $order_type, $order_reference);
      
      return $result 
        ? new JsonResponse($result)
        : $this->createErrorResponse('Failed to initiate payment order', [], self::HTTP_INTERNAL_ERROR);
    }
    catch (\Exception $e) {
      $this->logger->error('Initiate payment order error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return $this->createErrorResponse('Failed to initiate payment order', [], self::HTTP_INTERNAL_ERROR);
    }
  }

  /**
   * Process refund endpoint.
   */
  public function processRefund(Request $request, string $order_id): JsonResponse {
    try {
      $data = $this->parseJsonRequest($request);
      
      $amount = $data['amount'] ?? 0;
      $merchant_code = $data['merchant_code'] ?? NULL;
      
      if ($amount <= 0) {
        return $this->createErrorResponse('Invalid amount', [], self::HTTP_BAD_REQUEST);
      }
      
      $result = $this->securePayApi->refundPayment($order_id, $amount, $merchant_code);
      
      if ($result) {
        $this->logRefund($order_id, $amount, $result);
        return new JsonResponse($result);
      }
      
      return $this->createErrorResponse('Failed to process refund', [], self::HTTP_INTERNAL_ERROR);
    }
    catch (\Exception $e) {
      $this->logger->error('Refund processing error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return $this->createErrorResponse('Failed to process refund', [], self::HTTP_INTERNAL_ERROR);
    }
  }

  /**
   * Get payment status endpoint.
   */
  public function getPaymentStatus(string $order_id): JsonResponse {
    // This would require implementing a payment status retrieval method
    return new JsonResponse([
      'order_id' => $order_id,
      'status' => 'not_implemented',
      'message' => 'Payment status retrieval not yet implemented',
    ]);
  }

  /**
   * Health check endpoint.
   */
  public function healthCheck(): JsonResponse {
    try {
      $api_status = $this->securePayApi->testConnection();
      $config_status = $this->configService->isConfigured();
      
      return new JsonResponse([
        'status' => $api_status && $config_status ? 'healthy' : 'unhealthy',
        'api_connection' => $api_status,
        'configuration' => $config_status,
        'timestamp' => time(),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => time(),
      ], self::HTTP_INTERNAL_ERROR);
    }
  }

  /**
   * Parse JSON request data.
   */
  protected function parseJsonRequest(Request $request): array {
    $content = $request->getContent();
    
    if (empty($content)) {
      throw new \InvalidArgumentException('Invalid request');
    }
    
    $data = json_decode($content, TRUE);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \InvalidArgumentException('Invalid JSON');
    }
    
    return $data;
  }

  /**
   * Validate payment data.
   */
  protected function validatePaymentData(array $data): void {
    foreach (self::REQUIRED_PAYMENT_FIELDS as $field) {
      if (empty($data[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }
  }

  /**
   * Prepare payment data.
   */
  protected function preparePaymentData(array $data, Request $request): array {
    $payment_data = [
      'token' => $data['token'],
      'amount' => $data['amount'],
      'ip' => $request->getClientIp(),
    ];
    
    $optional_fields = [
      'customer_code', 'currency', 'order_id',
      'threed_secure_details', 'dcc_details', 'fraud_check_details',
    ];
    
    foreach ($optional_fields as $field) {
      if (!empty($data[$field])) {
        $payment_data[$field] = $data[$field];
      }
    }
    
    return $payment_data;
  }

  /**
   * Process fraud check.
   */
  protected function processFraudCheck(array $payment_data): array {
    $fraud_check_type = $this->configService->get('fraud_check_type');
    
    if (empty($fraud_check_type)) {
      return ['success' => TRUE];
    }
    
    try {
      // Placeholder for fraud check implementation
      return [
        'success' => TRUE,
        'details' => [
          'provider_reference_number' => uniqid('fraud_'),
          'score' => 25,
          'result' => 'PASSED',
        ],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Fraud check error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return [
        'success' => FALSE,
        'error' => 'Fraud check failed',
      ];
    }
  }

  /**
   * Verify webhook signature.
   */
  protected function verifyWebhookSignature(Request $request): void {
    $signature = $request->headers->get('X-SecurePay-Signature');
    $webhook_secret = $this->configService->get('webhook_secret');
    
    if (empty($signature) || empty($webhook_secret)) {
      throw new \InvalidArgumentException('Invalid signature');
    }
    
    $expected_signature = hash_hmac('sha256', $request->getContent(), $webhook_secret);
    
    if (!hash_equals($expected_signature, $signature)) {
      throw new \InvalidArgumentException('Invalid signature');
    }
  }

  /**
   * Process webhook event.
   */
  protected function processWebhookEvent(array $data): void {
    $event_type = $data['event_type'] ?? 'unknown';
    
    $event_handlers = [
      'payment.success' => 'handlePaymentSuccessWebhook',
      'payment.failed' => 'handlePaymentFailedWebhook',
      'refund.processed' => 'handleRefundWebhook',
      'chargeback.received' => 'handleChargebackWebhook',
    ];
    
    if (isset($event_handlers[$event_type])) {
      $this->{$event_handlers[$event_type]}($data);
    } else {
      $this->logger->info('Unknown webhook event type: @type', ['@type' => $event_type]);
    }
  }

  /**
   * Handle payment success webhook.
   */
  protected function handlePaymentSuccessWebhook(array $data): void {
    $this->logger->info('Payment success webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
  }

  /**
   * Handle payment failed webhook.
   */
  protected function handlePaymentFailedWebhook(array $data): void {
    $this->logger->warning('Payment failed webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
  }

  /**
   * Handle refund webhook.
   */
  protected function handleRefundWebhook(array $data): void {
    $this->logger->info('Refund webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
  }

  /**
   * Handle chargeback webhook.
   */
  protected function handleChargebackWebhook(array $data): void {
    $this->logger->warning('Chargeback webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
  }

  /**
   * Log transaction.
   */
  protected function logTransaction(array $payment_data, array $result): void {
    if ($this->configService->get('log_transactions')) {
      $this->logger->info('Transaction processed: @order_id - @status - @amount', [
        '@order_id' => $result['transaction_id'] ?? 'unknown',
        '@status' => $result['status'] ?? 'unknown',
        '@amount' => $payment_data['amount'] ?? '0',
      ]);
    }
  }

  /**
   * Log refund.
   */
  protected function logRefund(string $order_id, int $amount, array $result): void {
    $this->logger->info('Refund processed: @order_id - @amount - @status', [
      '@order_id' => $order_id,
      '@amount' => $amount,
      '@status' => $result['status'] ?? 'unknown',
    ]);
  }

  /**
   * Send notifications.
   */
  protected function sendNotifications(array $result): void {
    if (!$this->configService->get('email_notifications')) {
      return;
    }
    
    $notification_email = $this->configService->get('notification_email');
    if (empty($notification_email)) {
      return;
    }
    
    $mailManager = \Drupal::service('plugin.manager.mail');
    
    $params = [
      'result' => $result,
      'success' => $result['success'] ?? FALSE,
    ];
    
    $mailManager->mail(
      'webform_securepay',
      $result['success'] ? 'payment_success' : 'payment_failed',
      $notification_email,
      \Drupal::currentUser()->getPreferredLangcode(),
      $params
    );
  }

  /**
   * Create error response.
   */
  protected function createErrorResponse(string $message, array $additional_data = [], int $status_code = self::HTTP_BAD_REQUEST): JsonResponse {
    $data = [
      'success' => FALSE,
      'error' => $message,
    ] + $additional_data;
    
    return new JsonResponse($data, $status_code);
  }

}