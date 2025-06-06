<?php

namespace Drupal\webform_securepay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\webform_securepay\Service\SecurePayApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
   * Handle payment callback.
   */
  public function paymentCallback(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    
    if (empty($data)) {
      return new JsonResponse(['error' => 'Invalid request'], 400);
    }
    
    // Process the payment
    $result = $this->securePayApi->processPayment($data);
    
    return new JsonResponse($result);
  }

  /**
   * Test connection to SecurePay API.
   */
  public function testConnection() {
    $config = $this->config('webform_securepay.settings');
    
    // Simple test data
    $test_data = [
      'amount' => 100, // $1.00 in cents
      'card_number' => '4444333322221111', // Test card
      'expiry_date' => '12/25',
      'cvv' => '123',
    ];
    
    $result = $this->securePayApi->processPayment($test_data);
    
    if ($result['success']) {
      $this->messenger()->addMessage($this->t('SecurePay connection test successful.'));
    } else {
      $this->messenger()->addError($this->t('SecurePay connection test failed: @error', [
        '@error' => $result['error'] ?? 'Unknown error',
      ]));
    }
    
    return $this->redirect('webform_securepay.admin_settings');
  }

}