<?php

namespace Drupal\webform_securepay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\PaymentProcessorInterface;
use Drupal\webform_securepay\Service\WebhookProcessorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for SecurePay operations.
 */
class SecurePayController extends ControllerBase implements ContainerInjectionInterface {

  private const HTTP_OK = 200;
  private const HTTP_BAD_REQUEST = 400;
  private const HTTP_INTERNAL_ERROR = 500;
  private const REQUIRED_PAYMENT_FIELDS = ['token', 'amount'];

  public function __construct(
    private readonly SecurePayApiServiceInterface $securePayApi,
    private readonly ConfigurationService $configService,
    private readonly PaymentProcessorInterface $paymentProcessor,
    private readonly WebhookProcessorInterface $webhookProcessor,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.api'),
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.payment_processor'),
      $container->get('webform_securepay.webhook_processor'),
      $container->get('logger.factory')->get('webform_securepay')
    );
  }

  public function paymentCallback(Request $request): JsonResponse {
    try {
      $data = $this->parseJsonRequest($request);
      $this->validatePaymentData($data);
      
      $result = $this->paymentProcessor->processPayment($data, $request);
      
      return new JsonResponse($result);
    }
    catch (\InvalidArgumentException $e) {
      return $this->createErrorResponse($e->getMessage(), [], self::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment callback error: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('Payment processing failed', [], self::HTTP_INTERNAL_ERROR);
    }
  }

  public function webhook(Request $request): Response {
    if (!$this->configService->get('webhook_enabled')) {
      return new Response('Webhook not enabled', Response::HTTP_NOT_FOUND);
    }
    
    try {
      $data = $this->parseJsonRequest($request);
      $this->webhookProcessor->processWebhook($data, $request);
      
      return new Response('OK', self::HTTP_OK);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('Invalid webhook: @message', ['@message' => $e->getMessage()]);
      return new Response($e->getMessage(), self::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing error: @message', ['@message' => $e->getMessage()]);
      return new Response('Processing failed', self::HTTP_INTERNAL_ERROR);
    }
  }

  public function testConnection(): Response {
    try {
      $success = $this->securePayApi->testConnection();
      $message = $success 
        ? $this->t('SecurePay connection test successful.')
        : $this->t('SecurePay connection test failed.');
      
      $this->messenger()->addMessage($message, $success ? 'status' : 'error');
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Connection test failed: @error', ['@error' => $e->getMessage()]));
    }
    
    return $this->redirect('webform_securepay.admin_settings');
  }

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
      $this->logger->error('Initiate payment order error: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('Failed to initiate payment order', [], self::HTTP_INTERNAL_ERROR);
    }
  }

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
      $this->logger->error('Refund processing error: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('Failed to process refund', [], self::HTTP_INTERNAL_ERROR);
    }
  }

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

  private function parseJsonRequest(Request $request): array {
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

  private function validatePaymentData(array $data): void {
    foreach (self::REQUIRED_PAYMENT_FIELDS as $field) {
      if (empty($data[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }
  }

  private function logRefund(string $order_id, int $amount, array $result): void {
    $this->logger->info('Refund processed: @order_id - @amount - @status', [
      '@order_id' => $order_id,
      '@amount' => $amount,
      '@status' => $result['status'] ?? 'unknown',
    ]);
  }

  private function createErrorResponse(string $message, array $additional_data = [], int $status_code = self::HTTP_BAD_REQUEST): JsonResponse {
    $data = ['success' => FALSE, 'error' => $message] + $additional_data;
    return new JsonResponse($data, $status_code);
  }
}