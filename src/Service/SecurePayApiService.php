<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Service for SecurePay API interactions using modern REST API.
 */
class SecurePayApiService implements SecurePayApiServiceInterface {

  use StringTranslationTrait;

  private const API_ENDPOINTS = [
    'live' => [
      'auth' => 'https://welcome.api2.auspost.com.au/oauth/token',
      'api' => 'https://payments.auspost.net.au',
      'ui_sdk' => 'https://payments.auspost.net.au/v3/ui/client/securepay-ui.min.js',
      'threeDS_sdk' => 'https://api.securepay.com.au/threeds-js/securepay-threeds.js',
    ],
    'sandbox' => [
      'auth' => 'https://welcome.api2.sandbox.auspost.com.au/oauth/token',
      'api' => 'https://payments-stest.npe.auspost.zone',
      'ui_sdk' => 'https://payments-stest.npe.auspost.zone/v3/ui/client/securepay-ui.min.js',
      'threeDS_sdk' => 'https://test.api.securepay.com.au/threeds-js/securepay-threeds.js',
    ],
  ];

  private const DEFAULT_TIMEOUT = 30;
  private const TOKEN_BUFFER_SECONDS = 60;
  private const AUDIENCE = 'https://api.payments.auspost.com.au';
  private const GRANT_TYPE = 'client_credentials';

  private ?string $accessToken = NULL;
  private int $tokenExpiry = 0;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  public function processPayment(array $payment_data, array $element_settings = []): array {
    $settings = $this->mergeSettings($element_settings);
    $this->validatePaymentSettings($settings);

    $payment_request = $this->buildPaymentRequest($payment_data, $settings);

    try {
      $result = $this->makeApiRequest('POST', '/v2/payments', $payment_request);
      $this->logTransaction($payment_request['orderId'], $result);

      return $this->formatPaymentResult($result, $payment_data);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment processing failed: @message', ['@message' => $e->getMessage()]);
      
      return [
        'success' => FALSE,
        'error' => $this->t('Payment processing failed. Please try again.'),
      ];
    }
  }

  public function initiatePaymentOrder(int $amount, string $order_type = 'DYNAMIC_CURRENCY_CONVERSION', ?string $order_reference = NULL): array|false {
    $merchant_code = $this->getConfig('merchant_code');
    if (empty($merchant_code)) {
      throw new \InvalidArgumentException('Merchant code is required');
    }

    $order_request = [
      'merchantCode' => $merchant_code,
      'amount' => $amount,
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
      'orderType' => $order_type,
    ];

    if ($order_reference) {
      $order_request['orderReference'] = $order_reference;
    }

    try {
      return $this->makeApiRequest('POST', '/v2/payments/orders/initiate', $order_request);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to initiate payment order: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  public function refundPayment(string $order_id, int $amount, ?string $merchant_code = NULL): array|false {
    $refund_request = [
      'merchantCode' => $merchant_code ?: $this->getConfig('merchant_code'),
      'amount' => $amount,
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ];

    try {
      return $this->makeApiRequest('POST', "/v2/orders/{$order_id}/refunds", $refund_request);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process refund: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  public function testConnection(): bool {
    try {
      $result = $this->makeApiRequest('GET', '/v2/health');
      return ($result['status'] ?? '') === 'UP';
    }
    catch (\Exception $e) {
      $this->logger->error('Connection test failed: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  public function getUiSdkUrl(): string {
    $environment = $this->getConfig('environment', 'sandbox');
    return self::API_ENDPOINTS[$environment]['ui_sdk'];
  }

  public function getThreeDS2SdkUrl(): string {
    $environment = $this->getConfig('environment', 'sandbox');
    return self::API_ENDPOINTS[$environment]['threeDS_sdk'];
  }

  private function getAccessToken(): string {
    if ($this->accessToken && time() < $this->tokenExpiry) {
      return $this->accessToken;
    }

    $credentials = $this->validateCredentials();
    $auth_url = $this->getEndpoint('auth');

    try {
      $response = $this->httpClient->post($auth_url, [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => self::GRANT_TYPE,
          'audience' => self::AUDIENCE,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (empty($data['access_token'])) {
        throw new \Exception('Invalid token response');
      }

      $this->accessToken = $data['access_token'];
      $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - self::TOKEN_BUFFER_SECONDS;

      return $this->accessToken;
    }
    catch (RequestException $e) {
      throw new \Exception('Authentication failed: ' . $e->getMessage());
    }
  }

  private function makeApiRequest(string $method, string $endpoint, ?array $data = NULL): array {
    $token = $this->getAccessToken();
    $base_url = $this->getEndpoint('api');

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
      ],
      'timeout' => $this->getConfig('timeout', self::DEFAULT_TIMEOUT),
    ];

    if ($data) {
      $options['json'] = $data;
    }

    try {
      $response = $this->httpClient->request($method, $base_url . $endpoint, $options);
      return json_decode($response->getBody()->getContents(), TRUE) ?: [];
    }
    catch (RequestException $e) {
      throw new \Exception('API request failed: ' . $e->getMessage());
    }
  }

  private function validateCredentials(): array {
    $client_id = $this->getConfig('client_id');
    $client_secret = $this->getConfig('client_secret');

    if (empty($client_id) || empty($client_secret)) {
      throw new \InvalidArgumentException('Client ID and Client Secret are required');
    }

    return ['client_id' => $client_id, 'client_secret' => $client_secret];
  }

  private function buildPaymentRequest(array $payment_data, array $settings): array {
    $order_id = $this->generateOrderId($settings['order_id_prefix'] ?? 'WF_');

    $request = [
      'merchantCode' => $settings['merchant_code'],
      'amount' => $payment_data['amount'],
      'token' => $payment_data['token'],
      'ip' => $payment_data['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
      'orderId' => $order_id,
    ];

    $optional_fields = ['customer_code', 'currency', 'threed_secure_details', 'dcc_details', 'fraud_check_details'];
    foreach ($optional_fields as $field) {
      if (!empty($payment_data[$field])) {
        $request[str_replace('_', '', ucwords($field, '_'))] = $payment_data[$field];
      }
    }

    return $request;
  }

  private function formatPaymentResult(array $result, array $payment_data): array {
    return [
      'success' => ($result['status'] ?? '') === 'paid',
      'transaction_id' => $result['orderId'] ?? '',
      'bank_transaction_id' => $result['bankTransactionId'] ?? '',
      'status' => $result['status'] ?? 'unknown',
      'amount' => $result['amount'] ?? $payment_data['amount'],
      'currency' => $result['currency'] ?? 'AUD',
      'gateway_response_code' => $result['gatewayResponseCode'] ?? '',
      'gateway_response_message' => $result['gatewayResponseMessage'] ?? '',
      'created_at' => $result['createdAt'] ?? '',
      'raw_response' => $result,
    ];
  }

  private function validatePaymentSettings(array $settings): void {
    if (empty($settings['merchant_code'])) {
      throw new \InvalidArgumentException('Merchant code is required');
    }
  }

  private function logTransaction(string $order_id, array $result): void {
    if ($this->getConfig('log_transactions')) {
      $this->logger->info('SecurePay transaction: @order_id - @status', [
        '@order_id' => $order_id,
        '@status' => $result['status'] ?? 'unknown',
      ]);
    }
  }

  private function mergeSettings(array $element_settings): array {
    $keys = ['client_id', 'client_secret', 'merchant_code', 'environment', 'currency', 'timeout', 'order_id_prefix'];
    $settings = [];

    foreach ($keys as $key) {
      $settings[$key] = $element_settings[$key] ?? $this->getConfig($key);
    }

    return $settings;
  }

  private function generateOrderId(string $prefix = 'WF_'): string {
    return $prefix . time() . '_' . substr(uniqid(), -6);
  }

  private function getEndpoint(string $type): string {
    $environment = $this->getConfig('environment', 'sandbox');
    return self::API_ENDPOINTS[$environment][$type];
  }

  private function getConfig(string $key, $default = NULL) {
    return $this->configFactory->get('webform_securepay.settings')->get($key) ?? $default;
  }
}