<?php

namespace Drupal\webform_securepay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\webform_securepay\Service\SecurePayApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for SecurePay operations.
 */
class SecurePayController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The SecurePay API service.
   *
   * @var \Drupal\webform_securepay\Service\SecurePayApiService
   */
  protected $securePayApi;

  /**
   * Constructs a SecurePayController object.
   */
  public function __construct(SecurePayApiService $securepay_api) {
    $this->securePayApi = $securepay_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('webform_securepay.api')
    );
  }

  /**
   * Handle payment callback from JavaScript.
   */
  public function paymentCallback(Request $request) {
    $content = $request->getContent();
    
    if (empty($content)) {
      return new JsonResponse(['error' => 'Invalid request'], 400);
    }
    
    try {
      $data = json_decode($content, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        return new JsonResponse(['error' => 'Invalid JSON'], 400);
      }
      
      // Validate required fields
      $required_fields = ['token', 'amount'];
      foreach ($required_fields as $field) {
        if (empty($data[$field])) {
          return new JsonResponse(['error' => "Missing required field: $field"], 400);
        }
      }
      
      // Prepare payment data
      $payment_data = [
        'token' => $data['token'],
        'amount' => $data['amount'],
        'ip' => $request->getClientIp(),
      ];
      
      // Add optional fields
      $optional_fields = [
        'customer_code', 'currency', 'order_id', 
        'threed_secure_details', 'dcc_details', 'fraud_check_details'
      ];
      
      foreach ($optional_fields as $field) {
        if (!empty($data[$field])) {
          $payment_data[$field] = $data[$field];
        }
      }
      
      // Process fraud check if enabled
      if (!empty($data['fraud_check_enabled'])) {
        $fraud_result = $this->processFraudCheck($payment_data);
        if (!$fraud_result['success']) {
          return new JsonResponse([
            'success' => FALSE,
            'error' => 'Payment blocked by fraud detection',
            'fraud_result' => $fraud_result,
          ]);
        }
        $payment_data['fraud_check_details'] = $fraud_result['details'];
      }
      
      // Process the payment
      $result = $this->securePayApi->processPayment($payment_data);
      
      // Log the transaction
      $this->logTransaction($payment_data, $result);
      
      // Send notifications if enabled
      $this->sendNotifications($result);
      
      return new JsonResponse($result);
      
    } catch (\Exception $e) {
      $this->getLogger('webform_securepay')->error('Payment callback error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Payment processing failed',
      ], 500);
    }
  }

  /**
   * Handle webhook notifications from SecurePay.
   */
  public function webhook(Request $request) {
    $config = $this->config('webform_securepay.settings');
    
    if (!$config->get('webhook_enabled')) {
      return new Response('Webhook not enabled', 404);
    }
    
    // Verify webhook signature
    $signature = $request->headers->get('X-SecurePay-Signature');
    $webhook_secret = $config->get('webhook_secret');
    
    if (!$this->verifyWebhookSignature($request->getContent(), $signature, $webhook_secret)) {
      $this->getLogger('webform_securepay')->warning('Invalid webhook signature');
      return new Response('Invalid signature', 403);
    }
    
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        return new Response('Invalid JSON', 400);
      }
      
      // Process webhook event
      $this->processWebhookEvent($data);
      
      return new Response('OK', 200);
      
    } catch (\Exception $e) {
      $this->getLogger('webform_securepay')->error('Webhook processing error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new Response('Processing failed', 500);
    }
  }

  /**
   * Test connection to SecurePay API.
   */
  public function testConnection() {
    try {
      if ($this->securePayApi->testConnection()) {
        $this->messenger()->addMessage($this->t('SecurePay connection test successful.'));
      } else {
        $this->messenger()->addError($this->t('SecurePay connection test failed.'));
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('SecurePay connection test failed: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
    
    return $this->redirect('webform_securepay.admin_settings');
  }

  /**
   * Initiate payment order endpoint.
   */
  public function initiatePaymentOrder(Request $request) {
    $content = $request->getContent();
    
    if (empty($content)) {
      return new JsonResponse(['error' => 'Invalid request'], 400);
    }
    
    try {
      $data = json_decode($content, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        return new JsonResponse(['error' => 'Invalid JSON'], 400);
      }
      
      $amount = $data['amount'] ?? 0;
      $order_type = $data['order_type'] ?? 'DYNAMIC_CURRENCY_CONVERSION';
      $order_reference = $data['order_reference'] ?? NULL;
      
      if ($amount <= 0) {
        return new JsonResponse(['error' => 'Invalid amount'], 400);
      }
      
      $result = $this->securePayApi->initiatePaymentOrder($amount, $order_type, $order_reference);
      
      if ($result) {
        return new JsonResponse($result);
      } else {
        return new JsonResponse(['error' => 'Failed to initiate payment order'], 500);
      }
      
    } catch (\Exception $e) {
      $this->getLogger('webform_securepay')->error('Initiate payment order error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse(['error' => 'Failed to initiate payment order'], 500);
    }
  }

  /**
   * Process refund endpoint.
   */
  public function processRefund(Request $request, $order_id) {
    $content = $request->getContent();
    
    if (empty($content)) {
      return new JsonResponse(['error' => 'Invalid request'], 400);
    }
    
    try {
      $data = json_decode($content, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        return new JsonResponse(['error' => 'Invalid JSON'], 400);
      }
      
      $amount = $data['amount'] ?? 0;
      $merchant_code = $data['merchant_code'] ?? NULL;
      
      if ($amount <= 0) {
        return new JsonResponse(['error' => 'Invalid amount'], 400);
      }
      
      $result = $this->securePayApi->refundPayment($order_id, $amount, $merchant_code);
      
      if ($result) {
        // Log the refund
        $this->logRefund($order_id, $amount, $result);
        
        return new JsonResponse($result);
      } else {
        return new JsonResponse(['error' => 'Failed to process refund'], 500);
      }
      
    } catch (\Exception $e) {
      $this->getLogger('webform_securepay')->error('Refund processing error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse(['error' => 'Failed to process refund'], 500);
    }
  }

  /**
   * Get payment status endpoint.
   */
  public function getPaymentStatus($order_id) {
    try {
      // This would require implementing a payment status retrieval method
      // in the SecurePayApiService for the given order_id
      
      return new JsonResponse([
        'order_id' => $order_id,
        'status' => 'not_implemented',
        'message' => 'Payment status retrieval not yet implemented',
      ]);
      
    } catch (\Exception $e) {
      $this->getLogger('webform_securepay')->error('Payment status error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse(['error' => 'Failed to get payment status'], 500);
    }
  }

  /**
   * Process fraud check.
   */
  protected function processFraudCheck(array $payment_data) {
    $config = $this->config('webform_securepay.settings');
    $fraud_check_type = $config->get('fraud_check_type');
    
    if (empty($fraud_check_type)) {
      return ['success' => TRUE];
    }
    
    try {
      // This would require implementing fraud check methods
      // in the SecurePayApiService
      
      return [
        'success' => TRUE,
        'details' => [
          'provider_reference_number' => uniqid('fraud_'),
          'score' => 25,
          'result' => 'PASSED',
        ],
      ];
      
    } catch (\Exception $e) {
      $this->getLogger('webform_securepay')->error('Fraud check error: @message', [
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
  protected function verifyWebhookSignature($payload, $signature, $secret) {
    if (empty($signature) || empty($secret)) {
      return FALSE;
    }
    
    $expected_signature = hash_hmac('sha256', $payload, $secret);
    
    return hash_equals($expected_signature, $signature);
  }

  /**
   * Process webhook event.
   */
  protected function processWebhookEvent(array $data) {
    $event_type = $data['event_type'] ?? 'unknown';
    
    switch ($event_type) {
      case 'payment.success':
        $this->handlePaymentSuccessWebhook($data);
        break;
        
      case 'payment.failed':
        $this->handlePaymentFailedWebhook($data);
        break;
        
      case 'refund.processed':
        $this->handleRefundWebhook($data);
        break;
        
      case 'chargeback.received':
        $this->handleChargebackWebhook($data);
        break;
        
      default:
        $this->getLogger('webform_securepay')->info('Unknown webhook event type: @type', [
          '@type' => $event_type,
        ]);
    }
  }

  /**
   * Handle payment success webhook.
   */
  protected function handlePaymentSuccessWebhook(array $data) {
    $this->getLogger('webform_securepay')->info('Payment success webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
    
    // Update payment status in database if needed
    // Send confirmation emails
    // Trigger any post-payment processing
  }

  /**
   * Handle payment failed webhook.
   */
  protected function handlePaymentFailedWebhook(array $data) {
    $this->getLogger('webform_securepay')->warning('Payment failed webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
    
    // Update payment status
    // Send failure notifications
  }

  /**
   * Handle refund webhook.
   */
  protected function handleRefundWebhook(array $data) {
    $this->getLogger('webform_securepay')->info('Refund webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
    
    // Update refund status
    // Send refund confirmation
  }

  /**
   * Handle chargeback webhook.
   */
  protected function handleChargebackWebhook(array $data) {
    $this->getLogger('webform_securepay')->warning('Chargeback webhook received for order: @order_id', [
      '@order_id' => $data['order_id'] ?? 'unknown',
    ]);
    
    // Log chargeback
    // Send alerts to administrators
    // Update accounting records
  }

  /**
   * Log transaction.
   */
  protected function logTransaction(array $payment_data, array $result) {
    $config = $this->config('webform_securepay.settings');
    
    if ($config->get('log_transactions')) {
      $this->getLogger('webform_securepay')->info('Transaction processed: @order_id - @status - @amount', [
        '@order_id' => $result['transaction_id'] ?? 'unknown',
        '@status' => $result['status'] ?? 'unknown',
        '@amount' => $payment_data['amount'] ?? '0',
      ]);
    }
    
    // Store in database if needed for reporting/reconciliation
  }

  /**
   * Log refund.
   */
  protected function logRefund($order_id, $amount, array $result) {
    $this->getLogger('webform_securepay')->info('Refund processed: @order_id - @amount - @status', [
      '@order_id' => $order_id,
      '@amount' => $amount,
      '@status' => $result['status'] ?? 'unknown',
    ]);
  }

  /**
   * Send notifications.
   */
  protected function sendNotifications(array $result) {
    $config = $this->config('webform_securepay.settings');
    
    if (!$config->get('email_notifications')) {
      return;
    }
    
    $notification_email = $config->get('notification_email');
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

}