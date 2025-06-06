<?php

namespace Drupal\webform_securepay\Service;

use Drupal\webform_securepay\Exception\ApiException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Improved SecurePay API service with better error handling.
 */
class SecurePayApiService implements SecurePayApiServiceInterface {

  private const AUDIENCE = 'https://api.payments.auspost.com.au';
  private const GRANT_TYPE = 'client_credentials';
  private const TOKEN_BUFFER_SECONDS = 60;

  private ?string $accessToken = null;
  private int $tokenExpiry = 0;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigurationService $configService,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processPayment(array $payment_data, array $element_settings = []): array {
    $config = $this->configService->getSecurePayConfig();
    $request = $this->buildPaymentRequest($payment_data, $config);
    
    try {
      $response = $this->makeApiRequest('POST', '/v2/payments', $request);
      
      // Log successful payment
      if ($config->isDebugMode()) {
        $this->logger->debug('Payment processed successfully: {order_id}', [
          'order_id' => $request['orderId'],
        ]);
      }
      
      return $response;
    }
    catch (\Exception $e) {
      $this->logger->error('Payment processing failed: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $request['orderId'] ?? 'unknown',
      ]);
      throw new ApiException("Payment processing failed: {$e->getMessage()}", 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function initiatePaymentOrder(int $amount, string $order_type = 'DYNAMIC_CURRENCY_CONVERSION', ?string $order_reference = null): array|false {
    $config = $this->configService->getSecurePayConfig();
    
    $request = [
      'merchantCode' => $config->getMerchantCode(),
      'amount' => $amount,
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
      'orderType' => $order_type,
    ];

    if ($order_reference) {
      $request['orderReference'] = $order_reference;
    }

    try {
      return $this->makeApiRequest('POST', '/v2/payments/orders/initiate', $request);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to initiate payment order: {message}', [
        'message' => $e->getMessage(),
        'order_type' => $order_type,
        'amount' => $amount,
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(string $order_id, int $amount, ?string $merchant_code = null): array|false {
    $config = $this->configService->getSecurePayConfig();
    
    $request = [
      'merchantCode' => $merchant_code ?: $config->getMerchantCode(),
      'amount' => $amount,
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ];

    try {
      $response = $this->makeApiRequest('POST', "/v2/orders/{$order_id}/refunds", $request);
      
      $this->logger->info('Refund processed: {order_id} for {amount} cents', [
        'order_id' => $order_id,
        'amount' => $amount,
      ]);
      
      return $response;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process refund: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $order_id,
        'amount' => $amount,
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(): bool {
    try {
      $result = $this->makeApiRequest('GET', '/v2/health');
      $isHealthy = ($result['status'] ?? '') === 'UP';
      
      if ($isHealthy) {
        $this->logger->info('SecurePay API connection test successful');
      } else {
        $this->logger->warning('SecurePay API connection test failed: unhealthy status');
      }
      
      return $isHealthy;
    }
    catch (\Exception $e) {
      $this->logger->error('SecurePay API connection test failed: {message}', [
        'message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUiSdkUrl(): string {
    $config = $this->configService->getSecurePayConfig();
    $endpoints = $config->getApiEndpoints();
    return $endpoints['ui_sdk'];
  }

  /**
   * {@inheritdoc}
   */
  public function getThreeDS2SdkUrl(): string {
    $config = $this->configService->getSecurePayConfig();
    $endpoints = $config->getApiEndpoints();
    return $endpoints['threeDS_sdk'];
  }

  /**
   * Get or refresh OAuth 2.0 access token.
   */
  private function getAccessToken(): string {
    if ($this->accessToken && time() < $this->tokenExpiry) {
      return $this->accessToken;
    }

    $config = $this->configService->getSecurePayConfig();
    $endpoints = $config->getApiEndpoints();

    try {
      $response = $this->httpClient->post($endpoints['auth'], [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($config->getClientId() . ':' . $config->getClientSecret()),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => self::GRANT_TYPE,
          'audience' => self::AUDIENCE,
        ],
        'timeout' => $config->getTimeout(),
      ]);

      $data = json_decode($response->getBody()->getContents(), true);
      
      if (empty($data['access_token'])) {
        throw new ApiException('Invalid token response from SecurePay');
      }

      $this->accessToken = $data['access_token'];
      $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - self::TOKEN_BUFFER_SECONDS;

      if ($config->isDebugMode()) {
        $this->logger->debug('OAuth token refreshed, expires in {seconds} seconds', [
          'seconds' => $data['expires_in'] ?? 3600,
        ]);
      }

      return $this->accessToken;
    }
    catch (RequestException $e) {
      $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      
      if ($statusCode === 401) {
        throw ApiException::authenticationFailed('Invalid client credentials');
      }
      
      throw new ApiException("Authentication failed: {$e->getMessage()}", $statusCode, $e);
    }
  }

  /**
   * Make authenticated API request.
   */
  private function makeApiRequest(string $method, string $endpoint, ?array $data = null): array {
    $token = $this->getAccessToken();
    $config = $this->configService->getSecurePayConfig();
    $endpoints = $config->getApiEndpoints();

    $options = [
      'headers' => [
        'Authorization' => "Bearer {$token}",
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'timeout' => $config->getTimeout(),
    ];

    if ($data) {
      $options['json'] = $data;
    }

    try {
      $response = $this->httpClient->request($method, $endpoints['api'] . $endpoint, $options);
      $responseData = json_decode($response->getBody()->getContents(), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new ApiException('Invalid JSON response from SecurePay API');
      }
      
      return $responseData ?: [];
    }
    catch (RequestException $e) {
      $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      
      // Handle specific HTTP status codes
      switch ($statusCode) {
        case 400:
          throw ApiException::httpError($statusCode, 'Bad request - check payment data');
        case 401:
          throw ApiException::authenticationFailed();
        case 429:
          throw ApiException::apiRateLimited();
        case 503:
          throw ApiException::serviceUnavailable();
        default:
          throw new ApiException("API request failed: {$e->getMessage()}", $statusCode, $e);
      }
    }
  }

  /**
   * Build payment request payload.
   */
  private function buildPaymentRequest(array $payment_data, $config): array {
    return [
      'merchantCode' => $config->getMerchantCode(),
      'amount' => $payment_data['amount'],
      'token' => $payment_data['token'],
      'ip' => $payment_data['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
      'orderId' => $payment_data['orderId'],
      'currency' => $payment_data['currency'] ?? $config->getCurrency(),
    ];
  }
}