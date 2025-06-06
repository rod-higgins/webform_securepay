<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Service for SecurePay API interactions using modern REST API.
 */
class SecurePayApiService implements SecurePayApiServiceInterface {

  use StringTranslationTrait;

  // API Configuration Constants
  public const LIVE_AUTH_URL = 'https://welcome.api2.auspost.com.au/oauth/token';
  public const SANDBOX_AUTH_URL = 'https://welcome.api2.sandbox.auspost.com.au/oauth/token';
  public const LIVE_API_URL = 'https://payments.auspost.net.au';
  public const SANDBOX_API_URL = 'https://payments-stest.npe.auspost.zone';
  public const LIVE_UI_SDK_URL = 'https://payments.auspost.net.au/v3/ui/client/securepay-ui.min.js';
  public const SANDBOX_UI_SDK_URL = 'https://payments-stest.npe.auspost.zone/v3/ui/client/securepay-ui.min.js';
  public const LIVE_3DS_SDK_URL = 'https://api.securepay.com.au/threeds-js/securepay-threeds.js';
  public const SANDBOX_3DS_SDK_URL = 'https://test.api.securepay.com.au/threeds-js/securepay-threeds.js';

  // Default Values
  public const DEFAULT_TIMEOUT = 30;
  public const TOKEN_BUFFER_SECONDS = 60;
  public const AUDIENCE = 'https://api.payments.auspost.com.au';
  public const GRANT_TYPE = 'client_credentials';

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * The messenger service.
   */
  protected MessengerInterface $messenger;

  /**
   * Access token cache.
   */
  protected ?string $accessToken = NULL;

  /**
   * Token expiry time.
   */
  protected int $tokenExpiry = 0;

  /**
   * Constructs a SecurePayApiService object.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('webform_securepay');
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public function processPayment(array $payment_data, array $element_settings = []): array {
    $config = $this->configFactory->get('webform_securepay.settings');
    $settings = $this->mergeSettings($element_settings, $config);

    $this->validatePaymentSettings($settings);

    $payment_request = $this->buildPaymentRequest($payment_data, $settings);

    try {
      $result = $this->makeApiRequest('POST', '/v2/payments', $payment_request);
      $this->logTransaction($payment_request['orderId'], $result, $config);

      return $this->formatPaymentResult($result, $payment_data);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment processing failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [
        'success' => FALSE,
        'error' => $this->t('Payment processing failed. Please try again.'),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function initiatePaymentOrder(int $amount, string $order_type = 'DYNAMIC_CURRENCY_CONVERSION', ?string $order_reference = NULL) {
    $config = $this->configFactory->get('webform_securepay.settings');
    $merchant_code = $config->get('merchant_code');

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
      $this->logger->error('Failed to initiate payment order: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(string $order_id, int $amount, ?string $merchant_code = NULL): bool|array {
    $config = $this->configFactory->get('webform_securepay.settings');

    $refund_request = [
      'merchantCode' => $merchant_code ?: $config->get('merchant_code'),
      'amount' => $amount,
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ];

    try {
      return $this->makeApiRequest('POST', "/v2/orders/{$order_id}/refunds", $refund_request);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process refund: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(): bool {
    try {
      $result = $this->makeApiRequest('GET', '/v2/health');
      return ($result['status'] ?? '') === 'UP';
    }
    catch (\Exception $e) {
      $this->logger->error('Connection test failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUiSdkUrl(): string {
    return $this->isLiveEnvironment() ? self::LIVE_UI_SDK_URL : self::SANDBOX_UI_SDK_URL;
  }

  /**
   * {@inheritdoc}
   */
  public function getThreeDS2SdkUrl(): string {
    return $this->isLiveEnvironment() ? self::LIVE_3DS_SDK_URL : self::SANDBOX_3DS_SDK_URL;
  }

  /**
   * Get OAuth 2.0 access token.
   */
  protected function getAccessToken(): string {
    if ($this->accessToken && time() < $this->tokenExpiry) {
      return $this->accessToken;
    }

    $config = $this->configFactory->get('webform_securepay.settings');
    $credentials = $this->validateCredentials($config);

    $auth_url = $this->isLiveEnvironment() ? self::LIVE_AUTH_URL : self::SANDBOX_AUTH_URL;

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

  /**
   * Make authenticated API request.
   */
  protected function makeApiRequest(string $method, string $endpoint, ?array $data = NULL): array {
    $token = $this->getAccessToken();
    $base_url = $this->getApiBaseUrl();

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
      ],
      'timeout' => $this->getTimeout(),
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

  /**
   * Validate credentials.
   */
  protected function validateCredentials($config): array {
    $client_id = $config->get('client_id');
    $client_secret = $config->get('client_secret');

    if (empty($client_id) || empty($client_secret)) {
      throw new \InvalidArgumentException('Client ID and Client Secret are required');
    }

    return [
      'client_id' => $client_id,
      'client_secret' => $client_secret,
    ];
  }

  /**
   * Build payment request array.
   */
  protected function buildPaymentRequest(array $payment_data, array $settings): array {
    $order_id = $this->generateOrderId($settings['order_id_prefix'] ?? 'WF_');

    $request = [
      'merchantCode' => $settings['merchant_code'],
      'amount' => $payment_data['amount'],
      'token' => $payment_data['token'],
      'ip' => $payment_data['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
      'orderId' => $order_id,
    ];

    // Add optional parameters
    $optional_fields = ['customer_code', 'currency', 'threed_secure_details', 'dcc_details', 'fraud_check_details'];
    foreach ($optional_fields as $field) {
      if (!empty($payment_data[$field])) {
        $request[str_replace('_', '', ucwords($field, '_'))] = $payment_data[$field];
      }
    }

    return $request;
  }

  /**
   * Format payment result.
   */
  protected function formatPaymentResult(array $result, array $payment_data): array {
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

  /**
   * Validate payment settings.
   */
  protected function validatePaymentSettings(array $settings): void {
    if (empty($settings['merchant_code'])) {
      throw new \InvalidArgumentException('Merchant code is required');
    }
  }

  /**
   * Log transaction if enabled.
   */
  protected function logTransaction(string $order_id, array $result, $config): void {
    if ($config->get('log_transactions')) {
      $this->logger->info('SecurePay transaction: @order_id - @status', [
        '@order_id' => $order_id,
        '@status' => $result['status'] ?? 'unknown',
      ]);
    }
  }

  /**
   * Merge element settings with global configuration.
   */
  protected function mergeSettings(array $element_settings, $config): array {
    $keys = ['client_id', 'client_secret', 'merchant_code', 'environment', 'currency', 'timeout', 'order_id_prefix'];
    $settings = [];

    foreach ($keys as $key) {
      $settings[$key] = $element_settings[$key] ?? $config->get($key);
    }

    return $settings;
  }

  /**
   * Generate unique order ID.
   */
  protected function generateOrderId(string $prefix = 'WF_'): string {
    return $prefix . time() . '_' . substr(uniqid(), -6);
  }

  /**
   * Get API base URL based on environment.
   */
  protected function getApiBaseUrl(): string {
    return $this->isLiveEnvironment() ? self::LIVE_API_URL : self::SANDBOX_API_URL;
  }

  /**
   * Check if using live environment.
   */
  protected function isLiveEnvironment(): bool {
    $config = $this->configFactory->get('webform_securepay.settings');
    return $config->get('environment') === 'live';
  }

  /**
   * Get API timeout setting.
   */
  protected function getTimeout(): int {
    $config = $this->configFactory->get('webform_securepay.settings');
    return $config->get('timeout') ?: self::DEFAULT_TIMEOUT;
  }

}