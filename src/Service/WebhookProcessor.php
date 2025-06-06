<?php

namespace Drupal\webform_securepay\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Interface for webhook processor service.
 */
interface WebhookProcessorInterface {
  public function processWebhook(array $data, Request $request): void;
}

/**
 * Service for processing SecurePay webhooks.
 */
class WebhookProcessor implements WebhookProcessorInterface {

  private const EVENT_HANDLERS = [
    'payment.success' => 'handlePaymentSuccess',
    'payment.failed' => 'handlePaymentFailed',
    'refund.processed' => 'handleRefund',
    'chargeback.received' => 'handleChargeback',
  ];

  public function __construct(
    private readonly ConfigurationService $configService,
    private readonly LoggerInterface $logger,
  ) {}

  public function processWebhook(array $data, Request $request): void {
    $this->verifyWebhookSignature($request);
    
    $event_type = $data['event_type'] ?? 'unknown';
    
    if (isset(self::EVENT_HANDLERS[$event_type])) {
      $handler = self::EVENT_HANDLERS[$event_type];
      $this->$handler($data);
    } else {
      $this->logger->info('Unknown webhook event type: @type', ['@type' => $event_type]);
    }
  }

  private function verifyWebhookSignature(Request $request): void {
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

  private function handlePaymentSuccess(array $data): void {
    $this->logger->info('Payment success webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
  }

  private function handlePaymentFailed(array $data): void {
    $this->logger->warning('Payment failed webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
  }

  private function handleRefund(array $data): void {
    $this->logger->info('Refund webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
  }

  private function handleChargeback(array $data): void {
    $this->logger->warning('Chargeback webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
  }
}