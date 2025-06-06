<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for SecurePay API interactions using modern REST API.
 */
class SecurePayApiService {

  use StringTranslationTrait;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Access token cache.
   *
   * @var string
   */
  protected $accessToken;

  /**
   * Token expiry time.
   *
   * @var int
   */
  protected $tokenExpiry;

  /**
   * Constructs a SecurePayApiService object.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, MessengerInterface $messenger) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('webform_securepay');
    $this->messenger = $messenger;
  }

  /**
   * Get OAuth 2.0 access token.
   */
  protected function getAccessToken() {
    // Return cached token if still valid
    if ($this->accessToken && time() < $this->tokenExpiry) {
      return $this->accessToken;
    }

    $config = $this->configFactory->get('webform_securepay.settings');
    $client_id = $config->get('client_id');
    $client_secret = $config->get('client_secret');
    $environment = $config->get('environment') ?: 'sandbox';

    if (empty($client_id) || empty($client_secret)) {
      throw new \Exception('Client ID and Client Secret are required for OAuth 2.0 authentication');
    }

    $auth_url = $environment === 'live' 
      ? 'https://welcome.api2.auspost.com.au/oauth/token'
      : 'https://welcome.api2.sandbox.auspost.com.au/oauth/token';

    try {
      $response = $this->httpClient->post($auth_url, [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => 'client_credentials',
          'audience' => 'https://api.payments.auspost.com.au',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      
      if (isset($data['access_token'])) {
        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60; // 60 second buffer
        return $this->accessToken;
      }

      throw new \Exception('Failed to obtain access token');
    }
    catch (RequestException $e) {
      $this->logger->error('OAuth authentication failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Authentication failed: ' . $e->getMessage());
    }
  }

  /**
   * Get API base URL based on environment.
   */
  protected function getApiBaseUrl() {
    $config = $this->configFactory->get('webform_securepay.settings');
    $environment = $config->get('environment') ?: 'sandbox';
    
    return $environment === 'live' 
      ? 'https://payments.auspost.net.au'
      : 'https://payments-stest.npe.auspost.zone';
  }

  /**
   * Make authenticated API request.
   */
  protected function makeApiRequest($method, $endpoint, $data = NULL) {
    $token = $this->getAccessToken();
    $base_url = $this->getApiBaseUrl();
    
    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
      ],
    ];

    if ($data) {
      $options['json'] = $data;
    }

    try {
      $response = $this->httpClient->request($method, $base_url . $endpoint, $options);
      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (RequestException $e) {
      $this->logger->error('SecurePay API request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('API request failed: ' . $e->getMessage());
    }
  }

  /**
   * Process a payment through SecurePay REST API.
   */
  public function processPayment(array $payment_data, array $element_settings = []) {
    $config = $this->configFactory->get('webform_securepay.settings');
    
    // Merge element settings with global config
    $settings = $this->mergeSettings($element_settings, $config);
    
    // Validate required settings
    if (empty($settings['merchant_code'])) {
      $this->logger->error('SecurePay merchant code not configured');
      return [
        'success' => FALSE,
        'error' => $this->t('Payment configuration error'),
      ];
    }

    // Generate unique order ID
    $order_id = $this->generateOrderId($settings['order_id_prefix'] ?? 'WF_');
    
    $payment_request = [
      'merchantCode' => $settings['merchant_code'],
      'amount' => $payment_data['amount'],
      'token' => $payment_data['token'],
      'ip' => $payment_data['ip'] ?? $_SERVER['REMOTE_ADDR'],
      'orderId' => $order_id,
    ];

    // Add optional parameters
    if (!empty($payment_data['customer_code'])) {
      $payment_request['customerCode'] = $payment_data['customer_code'];
    }

    if (!empty($payment_data['currency'])) {
      $payment_request['currency'] = $payment_data['currency'];
    }

    // Add 3DS2 details if present
    if (!empty($payment_data['threed_secure_details'])) {
      $payment_request['threedSecureDetails'] = $payment_data['threed_secure_details'];
    }

    // Add DCC details if present
    if (!empty($payment_data['dcc_details'])) {
      $payment_request['dccDetails'] = $payment_data['dcc_details'];
    }

    // Add fraud check details if present
    if (!empty($payment_data['fraud_check_details'])) {
      $payment_request['fraudCheckDetails'] = $payment_data['fraud_check_details'];
    }

    try {
      $result = $this->makeApiRequest('POST', '/v2/payments', $payment_request);
      
      // Log transaction if enabled
      if ($config->get('log_transactions')) {
        $this->logger->info('SecurePay transaction: @order_id - @status', [
          '@order_id' => $order_id,
          '@status' => $result['status'] ?? 'unknown',
        ]);
      }
      
      return [
        'success' => ($result['status'] ?? '') === 'paid',
        'transaction_id' => $result['orderId'] ?? $order_id,
        'bank_transaction_id' => $result['bankTransactionId'] ?? '',
        'status' => $result['status'] ?? 'unknown',
        'amount' => $result['amount'] ?? $payment_data['amount'],
        'currency' => $result['currency'] ?? 'AUD',
        'gateway_response_code' => $result['gatewayResponseCode'] ?? '',
        'gateway_response_message' => $result['gatewayResponseMessage'] ?? '',
        'created_at' => $result['createdAt'] ?? '',
        'raw_response' => $result,
      ];
      
    } catch (\Exception $e) {
      $this->logger->error('SecurePay payment processing failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return [
        'success' => FALSE,
        'error' => $this->t('Payment processing failed. Please try again.'),
      ];
    }
  }

  /**
   * Initiate a payment order for DCC or 3DS2.
   */
  public function initiatePaymentOrder($amount, $order_type = 'DYNAMIC_CURRENCY_CONVERSION', $order_reference = NULL) {
    $config = $this->configFactory->get('webform_securepay.settings');
    $merchant_code = $config->get('merchant_code');

    $order_request = [
      'merchantCode' => $merchant_code,
      'amount' => $amount,
      'ip' => $_SERVER['REMOTE_ADDR'],
      'orderType' => $order_type,
    ];

    if ($order_reference) {
      $order_request['orderReference'] = $order_reference;
    }

    try {
      return $this->makeApiRequest('POST', '/v2/payments/orders/initiate', $order_request);
    } catch (\Exception $e) {
      $this->logger->error('Failed to initiate payment order: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Refund a payment.
   */
  public function refundPayment($order_id, $amount, $merchant_code = NULL) {
    $config = $this->configFactory->get('webform_securepay.settings');
    
    if (!$merchant_code) {
      $merchant_code = $config->get('merchant_code');
    }

    $refund_request = [
      'merchantCode' => $merchant_code,
      'amount' => $amount,
      'ip' => $_SERVER['REMOTE_ADDR'],
    ];

    try {
      return $this->makeApiRequest('POST', "/v2/orders/{$order_id}/refunds", $refund_request);
    } catch (\Exception $e) {
      $this->logger->error('Failed to process refund: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Test connection to SecurePay API.
   */
  public function testConnection() {
    try {
      $result = $this->makeApiRequest('GET', '/v2/health');
      return $result['status'] === 'UP';
    } catch (\Exception $e) {
      $this->logger->error('Connection test failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Generate unique order ID.
   */
  protected function generateOrderId($prefix = 'WF_') {
    return $prefix . time() . '_' . substr(uniqid(), -6);
  }

  /**
   * Merge element settings with global configuration.
   */
  protected function mergeSettings(array $element_settings, $config) {
    $settings = [];
    
    // Get from element settings first, then fallback to global config
    $keys = [
      'client_id', 'client_secret', 'merchant_code', 'environment', 
      'currency', 'timeout', 'order_id_prefix', 'test_mode'
    ];
    
    foreach ($keys as $key) {
      $settings[$key] = $element_settings[$key] ?? $config->get($key);
    }
    
    return $settings;
  }

  /**
   * Get the SecurePay UI JavaScript SDK URL.
   */
  public function getUiSdkUrl() {
    $config = $this->configFactory->get('webform_securepay.settings');
    $environment = $config->get('environment') ?: 'sandbox';
    
    return $environment === 'live' 
      ? 'https://payments.auspost.net.au/v3/ui/client/securepay-ui.min.js'
      : 'https://payments-stest.npe.auspost.zone/v3/ui/client/securepay-ui.min.js';
  }

  /**
   * Get the 3DS2 JavaScript SDK URL.
   */
  public function getThreeDS2SdkUrl() {
    $config = $this->configFactory->get('webform_securepay.settings');
    $environment = $config->get('environment') ?: 'sandbox';
    
    return $environment === 'live' 
      ? 'https://api.securepay.com.au/threeds-js/securepay-threeds.js'
      : 'https://test.api.securepay.com.au/threeds-js/securepay-threeds.js';
  }

}