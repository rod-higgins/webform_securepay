<?php

namespace Drupal\webform_securepay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\webform_securepay\Exception\PaymentException;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\PaymentProcessorInterface;
use Drupal\webform_securepay\Service\RateLimiter;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;
use Drupal\webform_securepay\Service\WebhookProcessorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simplified SecurePay controller.
 */
class SecurePayController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly PaymentProcessorInterface $paymentProcessor,
    private readonly SecurePayApiServiceInterface $apiService,
    private readonly ConfigurationService $configService,
    private readonly WebhookProcessorInterface $webhookProcessor,
    private readonly RateLimiter $rateLimiter,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.payment_processor'),
      $container->get('webform_securepay.api'),
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.webhook_processor'),
      $container->get('webform_securepay.rate_limiter'),
      $container->get('logger.factory')->get('webform_securepay')
    );
  }

  public function paymentCallback(Request $request): JsonResponse {
    try {
      $this->validateRequest($request);
      
      $data = $this->parseJsonRequest($request);
      $result = $this->paymentProcessor->processPayment($data, $request);
      
      return new JsonResponse($result);
    }
    catch (PaymentException $e) {
      return $this->createErrorResponse($e->getMessage(), Response::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment callback error: {message}', [
        'message' => $e->getMessage(),
      ]);
      return $this->createErrorResponse('Payment processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  public function webhook(Request $request): Response {
    if (!$this->configService->get('webhook_enabled')) {
      return new Response('Webhook not enabled', Response::HTTP_NOT_FOUND);
    }
    
    try {
      $data = $this->parseJsonRequest($request);
      $this->webhookProcessor->processWebhook($data, $request);
      
      return new Response('OK');
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing error: {message}', [
        'message' => $e->getMessage(),
      ]);
      return new Response('Processing failed', Response::HTTP_BAD_REQUEST);
    }
  }

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

  public function healthCheck(): JsonResponse {
    try {
      $apiStatus = $this->apiService->testConnection();
      $configStatus = $this->configService->isConfigured();
      
      return new JsonResponse([
        'status' => $apiStatus && $configStatus ? 'healthy' : 'unhealthy',
        'api_connection' => $apiStatus,
        'configuration' => $configStatus,
        'timestamp' => time(),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => time(),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  private function validateRequest(Request $request): void {
    $ipAddress = $request->getClientIp();
    
    if ($this->rateLimiter->isRateLimited($ipAddress)) {
      throw new PaymentException('Rate limit exceeded. Please try again later.');
    }
  }

  private function parseJsonRequest(Request $request): array {
    $content = $request->getContent();
    
    if (empty($content)) {
      throw new PaymentException('Empty request body');
    }
    
    $data = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new PaymentException('Invalid JSON format');
    }
    
    return $data;
  }

  private function createErrorResponse(string $message, int $statusCode = Response::HTTP_BAD_REQUEST): JsonResponse {
    return new JsonResponse([
      'success' => false,
      'error' => $message,
    ], $statusCode);
  }
}