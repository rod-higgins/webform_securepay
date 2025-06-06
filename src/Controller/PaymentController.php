<?php

namespace Drupal\webform_securepay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webform_securepay\Service\PaymentService;
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
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.payment'),
      $container->get('logger.factory')->get('webform_securepay')
    );
  }

  /**
   * Process payment callback.
   */
  public function callback(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->errorResponse('Invalid JSON', Response::HTTP_BAD_REQUEST);
      }

      $result = $this->paymentService->processPayment($data);
      
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment callback error: {message}', [
        'message' => $e->getMessage(),
        'ip' => $request->getClientIp(),
      ]);
      
      return $this->errorResponse('Payment processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Handle webhook notifications.
   */
  public function webhook(Request $request): Response {
    try {
      $data = json_decode($request->getContent(), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
      }

      // Simple webhook logging
      $this->logger->info('Webhook received: {type}', [
        'type' => $data['event_type'] ?? 'unknown',
        'data' => $data,
      ]);
      
      return new Response('OK');
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook error: {message}', [
        'message' => $e->getMessage(),
      ]);
      
      return new Response('Error', Response::HTTP_INTERNAL_SERVER_ERROR);
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