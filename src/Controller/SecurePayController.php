<?php

namespace Drupal\webform_securepay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\webform_securepay\Exception\PaymentException;
use Drupal\webform_securepay\Exception\ValidationException;
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
 * Improved SecurePay controller with better error handling and separation of concerns.
 */
class SecurePayController extends ControllerBase implements ContainerInjectionInterface {

  // HTTP status codes for consistent responses
  private const HTTP_OK = Response::HTTP_OK;
  private const HTTP_BAD_REQUEST = Response::HTTP_BAD_REQUEST;
  private const HTTP_UNAUTHORIZED = Response::HTTP_UNAUTHORIZED;
  private const HTTP_FORBIDDEN = Response::HTTP_FORBIDDEN;
  private const HTTP_NOT_FOUND = Response::HTTP_NOT_FOUND;
  private const HTTP_TOO_MANY_REQUESTS = Response::HTTP_TOO_MANY_REQUESTS;
  private const HTTP_INTERNAL_SERVER_ERROR = Response::HTTP_INTERNAL_SERVER_ERROR;

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

  /**
   * Process payment callback from frontend.
   */
  public function paymentCallback(Request $request): JsonResponse {
    try {
      $this->validatePaymentRequest($request);
      
      $data = $this->parseJsonRequest($request);
      $result = $this->paymentProcessor->processPayment($data, $request);
      
      $this->logPaymentAttempt($request, $result);
      
      return new JsonResponse($result, self::HTTP_OK);
    }
    catch (ValidationException $e) {
      return $this->createErrorResponse($e->getMessage(), self::HTTP_BAD_REQUEST);
    }
    catch (PaymentException $e) {
      return $this->createErrorResponse($e->getMessage(), self::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment callback error: {message}', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);
      return $this->createErrorResponse('Payment processing failed', self::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Handle webhook notifications from SecurePay.
   */
  public function webhook(Request $request): Response {
    if (!$this->configService->get(ConfigurationService::WEBHOOK_ENABLED)) {
      return new Response('Webhook not enabled', self::HTTP_NOT_FOUND);
    }
    
    try {
      $data = $this->parseJsonRequest($request);
      $this->webhookProcessor->processWebhook($data, $request);
      
      return new Response('OK', self::HTTP_OK);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('Webhook validation failed: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
      ]);
      return new Response('Invalid signature', self::HTTP_UNAUTHORIZED);
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing error: {message}', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);
      return new Response('Processing failed', self::HTTP_BAD_REQUEST);
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
      $configStatus = $this->configService->isConfigured();
      
      $status = $apiStatus && $configStatus ? 'healthy' : 'unhealthy';
      $httpCode = $status === 'healthy' ? self::HTTP_OK : self::HTTP_INTERNAL_SERVER_ERROR;
      
      return new JsonResponse([
        'status' => $status,
        'checks' => [
          'api_connection' => $apiStatus,
          'configuration' => $configStatus,
          'database' => $this->checkDatabaseConnection(),
        ],
        'timestamp' => time(),
        'version' => $this->getModuleVersion(),
      ], $httpCode);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => time(),
      ], self::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Validate payment request before processing.
   */
  private function validatePaymentRequest(Request $request): void {
    $ipAddress = $request->getClientIp();
    
    if (!$ipAddress || $this->rateLimiter->isRateLimited($ipAddress)) {
      throw new PaymentException('Rate limit exceeded. Please try again later.');
    }

    if (!$this->configService->isConfigured()) {
      throw new PaymentException('SecurePay is not properly configured.');
    }

    // Check for required headers
    if (!$request->headers->has('Content-Type') || 
        $request->headers->get('Content-Type') !== 'application/json') {
      throw new ValidationException('Content-Type must be application/json');
    }
  }

  /**
   * Parse and validate JSON request body.
   */
  private function parseJsonRequest(Request $request): array {
    $content = $request->getContent();
    
    if (empty($content)) {
      throw new ValidationException('Empty request body');
    }
    
    $data = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new ValidationException('Invalid JSON format: ' . json_last_error_msg());
    }
    
    if (!is_array($data)) {
      throw new ValidationException('Request body must be a JSON object');
    }
    
    return $data;
  }

  /**
   * Create standardized error response.
   */
  private function createErrorResponse(string $message, int $statusCode = self::HTTP_BAD_REQUEST): JsonResponse {
    $response = [
      'success' => false,
      'error' => $message,
      'timestamp' => time(),
    ];

    // Add additional context for specific error types
    if ($statusCode === self::HTTP_TOO_MANY_REQUESTS) {
      $response['retry_after'] = 3600; // 1 hour
    }

    return new JsonResponse($response, $statusCode);
  }

  /**
   * Log payment attempt for monitoring and debugging.
   */
  private function logPaymentAttempt(Request $request, array $result): void {
    $logLevel = $result['success'] ? 'info' : 'warning';
    
    $this->logger->log($logLevel, 'Payment attempt: {status}', [
      'status' => $result['success'] ? 'success' : 'failed',
      'transaction_id' => $result['transaction_id'] ?? 'unknown',
      'amount' => $result['amount'] ?? 0,
      'currency' => $result['currency'] ?? 'unknown',
      'ip' => $request->getClientIp(),
      'user_agent' => $request->headers->get('User-Agent'),
      'error' => $result['error'] ?? null,
    ]);
  }

  /**
   * Check database connection for health monitoring.
   */
  private function checkDatabaseConnection(): bool {
    try {
      \Drupal::database()->query('SELECT 1')->fetchField();
      return true;
    }
    catch (\Exception) {
      return false;
    }
  }

  /**
   * Get module version for monitoring.
   */
  private function getModuleVersion(): string {
    try {
      $info = \Drupal::service('extension.list.module')->getExtensionInfo('webform_securepay');
      return $info['version'] ?? 'unknown';
    }
    catch (\Exception) {
      return 'unknown';
    }
  }
}