<?php

namespace Drupal\webform_securepay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webform_securepay\Service\PaymentService;
use Drupal\webform_securepay\Service\ConfigurationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for payment processing.
 */
class PaymentController extends ControllerBase {

  // Request limits
  private const MAX_REQUEST_SIZE = 1048576; // 1MB
  private const MAX_FIELD_LENGTH = 1000;
  private const WEBHOOK_TIMEOUT = 30; // seconds
  
  // Rate limiting (basic)
  private const MAX_REQUESTS_PER_IP = 100;
  private const RATE_LIMIT_WINDOW = 3600; // 1 hour

  public function __construct(
    private readonly PaymentService $paymentService,
    private readonly ConfigurationService $config,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.payment'),
      $container->get('webform_securepay.configuration'),
      $container->get('logger.factory')->get('webform_securepay')
    );
  }

  /**
   * Process payment callback.
   */
  public function callback(Request $request): JsonResponse {
    // Check content length to prevent DoS
    $contentLength = $request->headers->get('Content-Length', 0);
    if ($contentLength > self::MAX_REQUEST_SIZE) {
      return $this->errorResponse('Request too large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    // Check if SecurePay is configured
    if (!$this->config->isConfigured()) {
      return $this->errorResponse('Payment system not configured', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    // Basic rate limiting check
    if (!$this->checkRateLimit($request)) {
      return $this->errorResponse('Too many requests', Response::HTTP_TOO_MANY_REQUESTS);
    }

    try {
      $content = trim($request->getContent());
      if (empty($content)) {
        return $this->errorResponse('Empty request body', Response::HTTP_BAD_REQUEST);
      }

      $data = json_decode($content, true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->warning('Invalid JSON in payment callback from {ip}: {error}', [
          'ip' => $this->getClientIp($request),
          'error' => json_last_error_msg(),
        ]);
        return $this->errorResponse('Invalid JSON: ' . json_last_error_msg(), Response::HTTP_BAD_REQUEST);
      }

      if (!is_array($data)) {
        return $this->errorResponse('Invalid data format', Response::HTTP_BAD_REQUEST);
      }

      // Validate and sanitize input data
      $sanitizedData = $this->validateAndSanitizePaymentData($data, $request);

      $result = $this->paymentService->processPayment($sanitizedData);
      
      return new JsonResponse($result);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('Payment validation error from {ip}: {message}', [
        'message' => $e->getMessage(),
        'ip' => $this->getClientIp($request),
      ]);
      
      return $this->errorResponse('Validation error: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment callback error from {ip}: {message}', [
        'message' => $e->getMessage(),
        'ip' => $this->getClientIp($request),
        'trace' => $e->getTraceAsString(),
      ]);
      
      return $this->errorResponse('Payment processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Handle webhook notifications.
   */
  public function webhook(Request $request): Response {
    // Set execution time limit for webhook processing
    set_time_limit(self::WEBHOOK_TIMEOUT);

    try {
      // Check content length
      $contentLength = $request->headers->get('Content-Length', 0);
      if ($contentLength > self::MAX_REQUEST_SIZE) {
        return new Response('Request too large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
      }

      $content = $request->getContent();
      if (empty($content)) {
        $this->logger->warning('Empty webhook received from {ip}', [
          'ip' => $this->getClientIp($request),
        ]);
        return new Response('Empty content', Response::HTTP_BAD_REQUEST);
      }

      $data = json_decode($content, true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->warning('Invalid JSON webhook from {ip}: {error}', [
          'ip' => $this->getClientIp($request),
          'error' => json_last_error_msg(),
        ]);
        return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
      }

      // Validate webhook data
      $webhookData = $this->validateWebhookData($data);
      if ($webhookData === null) {
        return new Response('Invalid webhook data', Response::HTTP_BAD_REQUEST);
      }

      // Log webhook for monitoring
      $this->logger->info('Webhook received: {type}', [
        'type' => $webhookData['event_type'],
        'order_id' => $webhookData['order_id'] ?? 'unknown',
        'ip' => $this->getClientIp($request),
        'timestamp' => $webhookData['timestamp'] ?? time(),
      ]);
      
      return new Response('OK', Response::HTTP_OK);
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing error from {ip}: {message}', [
        'message' => $e->getMessage(),
        'ip' => $this->getClientIp($request),
      ]);
      
      return new Response('Processing error', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Validate and sanitize payment data.
   */
  private function validateAndSanitizePaymentData(array $data, Request $request): array {
    // Required fields validation
    $requiredFields = ['token', 'amount', 'currency'];
    foreach ($requiredFields as $field) {
      if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }

    // Validate and sanitize each field
    $sanitized = [];
    
    // Token validation and sanitization
    $token = trim((string) $data['token']);
    if (strlen($token) > self::MAX_FIELD_LENGTH) {
      throw new \InvalidArgumentException('Token too long');
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $token)) {
      throw new \InvalidArgumentException('Token contains invalid characters');
    }
    $sanitized['token'] = $token;

    // Amount validation
    if (!is_numeric($data['amount'])) {
      throw new \InvalidArgumentException('Amount must be numeric');
    }
    $amount = (int) $data['amount'];
    if ($amount <= 0 || $amount > 999999999) {
      throw new \InvalidArgumentException('Invalid amount');
    }
    $sanitized['amount'] = $amount;

    // Currency validation
    $currency = strtoupper(trim((string) $data['currency']));
    if (!$this->config->isValidCurrency($currency)) {
      throw new \InvalidArgumentException('Invalid currency');
    }
    $sanitized['currency'] = $currency;

    // Optional fields
    if (isset($data['orderId'])) {
      $orderId = trim((string) $data['orderId']);
      if (strlen($orderId) > 255) {
        throw new \InvalidArgumentException('Order ID too long');
      }
      $sanitized['orderId'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $orderId);
    }

    // Add client IP
    $sanitized['ipAddress'] = $this->getClientIp($request);

    return $sanitized;
  }

  /**
   * Validate webhook data.
   */
  private function validateWebhookData(array $data): ?array {
    // Check required webhook fields
    if (empty($data['event_type'])) {
      return null;
    }

    $eventType = trim((string) $data['event_type']);
    if (strlen($eventType) > 100) {
      return null;
    }

    // Sanitize webhook data
    $sanitized = [
      'event_type' => preg_replace('/[^a-zA-Z0-9_.-]/', '', $eventType),
      'timestamp' => time(),
    ];

    // Optional fields
    if (isset($data['order_id'])) {
      $orderId = trim((string) $data['order_id']);
      if (strlen($orderId) <= 255) {
        $sanitized['order_id'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $orderId);
      }
    }

    if (isset($data['transaction_id'])) {
      $transactionId = trim((string) $data['transaction_id']);
      if (strlen($transactionId) <= 255) {
        $sanitized['transaction_id'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $transactionId);
      }
    }

    return $sanitized;
  }

  /**
   * Get client IP address safely.
   */
  private function getClientIp(Request $request): string {
    $ip = $request->getClientIp();
    
    // Validate IP address
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
      return $ip;
    }
    
    return 'unknown';
  }

  /**
   * Basic rate limiting check.
   */
  private function checkRateLimit(Request $request): bool {
    // Simple in-memory rate limiting for demonstration
    // In production, you might want to use Redis or database
    static $requestCounts = [];
    static $lastCleanup = 0;
    
    $now = time();
    $ip = $this->getClientIp($request);
    
    // Cleanup old entries every 5 minutes
    if ($now - $lastCleanup > 300) {
      foreach ($requestCounts as $checkIp => $data) {
        if ($now - $data['first_request'] > self::RATE_LIMIT_WINDOW) {
          unset($requestCounts[$checkIp]);
        }
      }
      $lastCleanup = $now;
    }
    
    // Check rate limit for this IP
    if (!isset($requestCounts[$ip])) {
      $requestCounts[$ip] = [
        'count' => 1,
        'first_request' => $now,
      ];
      return true;
    }
    
    $requestCounts[$ip]['count']++;
    
    if ($requestCounts[$ip]['count'] > self::MAX_REQUESTS_PER_IP) {
      $this->logger->warning('Rate limit exceeded for IP: {ip}', ['ip' => $ip]);
      return false;
    }
    
    return true;
  }

  /**
   * Create error response.
   */
  private function errorResponse(string $message, int $statusCode): JsonResponse {
    return new JsonResponse([
      'success' => false,
      'error' => $message,
      'timestamp' => time(),
    ], $statusCode);
  }
}