<?php

namespace Drupal\webform_securepay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\webform_securepay\Service\PaymentService;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;
use Drupal\webform_securepay\Service\WebhookProcessorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for SecurePay payment processing and webhooks.
 */
class SecurePayController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly PaymentService $paymentService,
    private readonly SecurePayApiServiceInterface $apiService,
    private readonly WebhookProcessorInterface $webhookProcessor,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.payment_service'),
      $container->get('webform_securepay.api'),
      $container->get('webform_securepay.webhook_processor'),
      $container->get('logger.factory')->get('webform_securepay')
    );
  }

  /**
   * Process payment callback from frontend.
   */
  public function paymentCallback(Request $request): JsonResponse {
    try {
      $this->validateRequest($request);
      
      $data = json_decode($request->getContent(), true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->errorResponse('Invalid JSON: ' . json_last_error_msg(), Response::HTTP_BAD_REQUEST);
      }

      $result = $this->paymentService->processPayment($data, $request);
      
      return new JsonResponse($result);
    }
    catch (\InvalidArgumentException $e) {
      return $this->errorResponse($e->getMessage(), Response::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment callback error: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
        'exception' => $e,
      ]);
      
      return $this->errorResponse('Payment processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Handle webhook notifications from SecurePay.
   */
  public function webhook(Request $request): Response {
    try {
      $data = json_decode($request->getContent(), true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->warning('Webhook received invalid JSON: {error}', [
          'error' => json_last_error_msg(),
          'ip' => $request->getClientIp(),
        ]);
        return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
      }

      $this->webhookProcessor->processWebhook($data, $request);
      
      return new Response('OK');
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('Webhook validation failed: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
      ]);
      return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing error: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
        'exception' => $e,
      ]);
      return new Response('Processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Test connection to SecurePay API.
   */
  public function testConnection(): Response {
    try {
      $success = $this->apiService->testConnection();
      $message = $success 
        ? $this->t('✅ SecurePay connection successful.')
        : $this->t('❌ SecurePay connection failed. Please check your credentials.');
      
      $this->messenger()->addMessage($message, $success ? 'status' : 'error');
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('❌ Connection test failed: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
    
    return $this->redirect('webform_securepay.admin_settings');
  }

  /**
   * Health check endpoint for monitoring.
   */
  public function healthCheck(): JsonResponse {
    try {
      $apiStatus = $this->apiService->testConnection();
      $stats = $this->paymentService->getTransactionStats(1); // Last 24 hours
      
      $status = $apiStatus ? 'healthy' : 'unhealthy';
      $httpCode = $apiStatus ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;
      
      return new JsonResponse([
        'status' => $status,
        'timestamp' => time(),
        'api_connection' => $apiStatus,
        'recent_transactions' => $stats['total'],
        'success_rate' => $stats['success_rate'] . '%',
        'service' => 'webform_securepay',
        'version' => '2.0',
      ], $httpCode);
    }
    catch (\Exception $e) {
      $this->logger->error('Health check failed: {message}', [
        'message' => $e->getMessage(),
        'exception' => $e,
      ]);
      
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Health check failed',
        'timestamp' => time(),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Get payment status by order ID.
   */
  public function getPaymentStatus(string $order_id): JsonResponse {
    try {
      // Validate order ID format
      if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $order_id)) {
        return $this->errorResponse('Invalid order ID format', Response::HTTP_BAD_REQUEST);
      }

      $transaction = $this->paymentService->getTransactionByOrderId($order_id);
      
      if (!$transaction) {
        return $this->errorResponse('Transaction not found', Response::HTTP_NOT_FOUND);
      }

      return new JsonResponse([
        'order_id' => $transaction['order_id'],
        'status' => $transaction['status'],
        'amount' => (int) $transaction['amount'],
        'currency' => $transaction['currency'],
        'created' => (int) $transaction['created'],
        'transaction_id' => $transaction['transaction_id'],
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get payment status: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $order_id,
        'exception' => $e,
      ]);
      
      return $this->errorResponse('Unable to retrieve payment status', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Initiate payment order for DCC or 3DS2.
   */
  public function initiatePaymentOrder(Request $request): JsonResponse {
    try {
      $this->validateRequest($request);
      
      $data = json_decode($request->getContent(), true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->errorResponse('Invalid JSON', Response::HTTP_BAD_REQUEST);
      }

      $amount = (int) ($data['amount'] ?? 0);
      $orderType = $data['order_type'] ?? 'DYNAMIC_CURRENCY_CONVERSION';
      
      if ($amount <= 0) {
        return $this->errorResponse('Invalid amount', Response::HTTP_BAD_REQUEST);
      }

      $result = $this->apiService->initiatePaymentOrder($amount, $orderType);
      
      if ($result === false) {
        return $this->errorResponse('Failed to initiate payment order', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to initiate payment order: {message}', [
        'message' => $e->getMessage(),
        'exception' => $e,
      ]);
      
      return $this->errorResponse('Order initiation failed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Process refund for a transaction.
   */
  public function processRefund(string $order_id, Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->errorResponse('Invalid JSON', Response::HTTP_BAD_REQUEST);
      }

      $amount = (int) ($data['amount'] ?? 0);
      if ($amount <= 0) {
        return $this->errorResponse('Invalid refund amount', Response::HTTP_BAD_REQUEST);
      }

      $result = $this->apiService->refundPayment($order_id, $amount);
      
      if ($result === false) {
        return $this->errorResponse('Refund processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process refund: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $order_id,
        'exception' => $e,
      ]);
      
      return $this->errorResponse('Refund failed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Validate incoming request.
   */
  private function validateRequest(Request $request): void {
    $ipAddress = $request->getClientIp();
    
    if (!$ipAddress) {
      throw new \InvalidArgumentException('Invalid client IP');
    }

    if ($this->paymentService->isRateLimited($ipAddress)) {
      throw new \InvalidArgumentException('Rate limit exceeded. Please try again later.');
    }

    $contentType = $request->headers->get('Content-Type');
    if ($contentType !== 'application/json') {
      throw new \InvalidArgumentException('Content-Type must be application/json');
    }

    if (empty($request->getContent())) {
      throw new \InvalidArgumentException('Empty request body');
    }
  }

  /**
   * Create error response.
   */
  private function errorResponse(string $message, int $statusCode = Response::HTTP_BAD_REQUEST): JsonResponse {
    return new JsonResponse([
      'success' => false,
      'error' => $message,
      'timestamp' => time(),
    ], $statusCode);
  }
}