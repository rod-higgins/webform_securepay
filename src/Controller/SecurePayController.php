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
 * Simplified SecurePay controller with focused responsibilities.
 */
class SecurePayController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly PaymentService $paymentService,
    private readonly SecurePayApiServiceInterface $apiService,
    private readonly WebhookProcessorInterface $webhookProcessor,
    private readonly LoggerInterface $logger,
  ) {}

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
        throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
      }

      $result = $this->paymentService->processPayment($data, $request);
      
      return new JsonResponse($result);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
      ], Response::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment callback error: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
      ]);
      
      return new JsonResponse([
        'success' => false,
        'error' => 'Payment processing failed',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Handle webhook notifications from SecurePay.
   */
  public function webhook(Request $request): Response {
    try {
      $data = json_decode($request->getContent(), true);
      if (json_last_error() !== JSON_ERROR_NONE) {
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
        ? $this->t('SecurePay connection successful.')
        : $this->t('SecurePay connection failed.');
      
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
        'api_connection' => $apiStatus,
        'recent_transactions' => $stats['total'],
        'success_rate' => $stats['success_rate'],
        'timestamp' => time(),
      ], $httpCode);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => time(),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Get payment status by order ID.
   */
  public function getPaymentStatus(string $order_id): JsonResponse {
    try {
      // This would typically query the transaction log
      $transaction = $this->paymentService->getTransactionByOrderId($order_id);
      
      if (!$transaction) {
        return new JsonResponse([
          'error' => 'Transaction not found',
        ], Response::HTTP_NOT_FOUND);
      }

      return new JsonResponse([
        'order_id' => $transaction['order_id'],
        'status' => $transaction['status'],
        'amount' => $transaction['amount'],
        'currency' => $transaction['currency'],
        'created' => $transaction['created'],
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get payment status: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $order_id,
      ]);
      
      return new JsonResponse([
        'error' => 'Unable to retrieve payment status',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  private function validateRequest(Request $request): void {
    $ipAddress = $request->getClientIp();
    
    if (!$ipAddress) {
      throw new \InvalidArgumentException('Invalid client IP');
    }

    if ($this->paymentService->isRateLimited($ipAddress)) {
      throw new \InvalidArgumentException('Rate limit exceeded');
    }

    if ($request->headers->get('Content-Type') !== 'application/json') {
      throw new \InvalidArgumentException('Content-Type must be application/json');
    }

    if (empty($request->getContent())) {
      throw new \InvalidArgumentException('Empty request body');
    }
  }
}