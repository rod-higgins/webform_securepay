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
    // Check if SecurePay is configured
    if (!$this->config->isConfigured()) {
      return $this->errorResponse('Payment system not configured', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    try {
      $content = trim($request->getContent());
      if (empty($content)) {
        return $this->errorResponse('Empty request body', Response::HTTP_BAD_REQUEST);
      }

      $data = json_decode($content, true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->errorResponse('Invalid JSON: ' . json_last_error_msg(), Response::HTTP_BAD_REQUEST);
      }

      if (!is_array($data)) {
        return $this->errorResponse('Invalid data format', Response::HTTP_BAD_REQUEST);
      }

      // Validate required fields
      $requiredFields = ['token', 'amount', 'currency'];
      foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
          return $this->errorResponse("Missing required field: {$field}", Response::HTTP_BAD_REQUEST);
        }
      }

      // Additional validation
      if (!is_numeric($data['amount']) || (int)$data['amount'] <= 0) {
        return $this->errorResponse('Invalid amount', Response::HTTP_BAD_REQUEST);
      }

      // Sanitize and add client IP
      $data['ipAddress'] = $request->getClientIp();
      $data['amount'] = (int)$data['amount'];
      $data['currency'] = strtoupper(trim($data['currency']));

      $result = $this->paymentService->processPayment($data);
      
      return new JsonResponse($result);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('Payment validation error: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
      ]);
      
      return $this->errorResponse('Validation error: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment callback error: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
        'trace' => $e->getTraceAsString(),
      ]);
      
      return $this->errorResponse('Payment processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Handle webhook notifications.
   */
  public function webhook(Request $request): Response {
    try {
      $content = $request->getContent();
      if (empty($content)) {
        $this->logger->warning('Empty webhook received from {ip}', [
          'ip' => $request->getClientIp(),
        ]);
        return new Response('Empty content', Response::HTTP_BAD_REQUEST);
      }

      $data = json_decode($content, true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->warning('Invalid JSON webhook from {ip}: {error}', [
          'ip' => $request->getClientIp(),
          'error' => json_last_error_msg(),
        ]);
        return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
      }

      // Basic webhook validation
      if (empty($data['event_type'])) {
        return new Response('Missing event type', Response::HTTP_BAD_REQUEST);
      }

      // Log webhook for monitoring
      $this->logger->info('Webhook received: {type}', [
        'type' => $data['event_type'],
        'order_id' => $data['order_id'] ?? 'unknown',
        'ip' => $request->getClientIp(),
        'data' => $data,
      ]);
      
      return new Response('OK');
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing error: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
      ]);
      
      return new Response('Processing error', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Create error response.
   */
  private function errorResponse(string $message, int $statusCode): JsonResponse {
    return new JsonResponse([
      'success' => false,
      'error' => $message,
    ], $statusCode);
  }
}