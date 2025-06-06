<?php

namespace Drupal\webform_securepay\Service;

use Drupal\webform_securepay\Exception\ApiException;
use Drupal\webform_securepay\Exception\ConfigurationException;
use Drupal\webform_securepay\ValueObject\PaymentRequest;
use Drupal\webform_securepay\ValueObject\PaymentResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * SecurePay API service.
 */
class SecurePayApiService implements SecurePayApiServiceInterface {

  private ?string $accessToken = null;
  private int $tokenExpiry = 0;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigurationService $config,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processPayment(array $payment_data, array $element_settings = []): array {
    if (!$this->config->isConfigured()) {
      throw ConfigurationException::missingConfiguration();
    }

    try {
      $request = PaymentRequest::fromArray([
        'token' => $payment_data['token'] ?? '',
        'amount' => (int) ($payment_data['amount'] ?? 0),
        'currency' => $payment_data['currency'] ?? $this->config->get('currency') ?? 'AUD',
        'merchantCode' => $this->config->get('merchant_code'),
        'orderId' => $payment_data['orderId'] ?? $this->generateOrderId(),
        'ipAddress' => $payment_data['ipAddress'] ?? null,
      ]);

      $response = $this->makeApiRequest('POST', '/v2/payments', $request->toArray());
      
      if (isset($response['transactionId'])) {
        return [
          'success' => true,
          'transaction_id' => $response['transactionId'],
          'status' => $response['status'] ?? 'completed',
          'raw_response' => $response,
        ];
      }
      
      throw ApiException::invalidResponse('No transaction ID in response');
    }
    catch (\Exception $e) {
      $this->logger->error('Payment failed: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $payment_data['orderId'] ?? 'unknown',
      ]);
      
      return [
        'success' => false,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function initiatePaymentOrder(int $amount, string $order_type = 'DYNAMIC_CURRENCY_CONVERSION', ?string $order_reference = null): array|false {
    try {
      $data = [
        'amount' => $amount,
        'orderType' => $order_type,
        'merchantCode' => $this->config->get('merchant_code'),
      ];

      if ($order_reference) {
        $data['orderReference'] = $order_reference;
      }

      $response = $this->makeApiRequest('POST', '/v2/orders', $data);
      return $response ?: false;
    }
    catch (\Exception $e) {
      $this->logger->error('Order initiation failed: {message}', [
        'message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(string $order_id, int $amount, ?string $merchant_code = null): array|false {
    try {
      $data = [
        'orderId' => $order_id,
        'amount' => $amount,
        'merchantCode' => $merchant_code ?? $this->config->get('merchant_code'),
      ];

      $response = $this->makeApiRequest('POST', '/v2/refunds', $data);
      return $response ?: false;
    }
    catch (\Exception $e) {
      $this->logger->error('Refund failed: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $order_id,
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(): bool {
    try {
      $this->getAccessToken();
      return true;
    }
    catch (\Exception $e) {
      $this->logger->error('Connection test failed: {message}', [
        'message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUiSdkUrl(): string {
    $endpoints = $this->config->getApiEndpoints();
    return $endpoints['ui_sdk'];
  }

  /**
   * {@inheritdoc}
   */
  public function getThreeDS2SdkUrl(): string {
    $endpoints = $this->config->getApiEndpoints();
    $isLive = $this->config->get('environment') === 'live';
    $baseUrl = $isLive 
      ? 'https://api.payments.auspost.com.au'
      : 'https://api.payments.test.auspost.com.au';
    
    return $baseUrl . '/3ds2/v1/sdk/threeds2.min.js';
  }

  /**
   * Generate unique order ID.
   */
  private function generateOrderId(): string {
    return 'WF_' . time() . '_' . substr(md5(uniqid()), 0, 8);
  }

  /**
   * Get or refresh access token.
   */
  private function getAccessToken(): string {
    if ($this->accessToken && time() < $this->tokenExpiry) {
      return $this->accessToken;
    }

    $endpoints = $this->config->getApiEndpoints();
    $clientId = $this->config->get('client_id');
    $clientSecret = $this->config->get('client_secret');

    if (empty($clientId) || empty($clientSecret)) {
      throw ConfigurationException::missingClientId();
    }

    try {
      $response = $this->httpClient->post($endpoints['auth'], [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => 'client_credentials',
          'audience' => 'https://api.payments.auspost.com.au',
        ],
        'timeout' => 30,
        'connect_timeout' => 10,
      ]);

      $data = json_decode($response->getBody()->getContents(), true);
      
      if (empty($data['access_token'])) {
        throw ApiException::invalidResponse('Invalid token response');
      }

      $this->accessToken = $data['access_token'];
      $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60;

      return $this->accessToken;
    }
    catch (RequestException $e) {
      throw ApiException::authenticationFailed($e->getMessage());
    }
  }

  /**
   * Make authenticated API request.
   */
  private function makeApiRequest(string $method, string $endpoint, ?array $data = null): array {
    $token = $this->getAccessToken();
    $endpoints = $this->config->getApiEndpoints();

    $options = [
      'headers' => [
        'Authorization' => "Bearer {$token}",
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'timeout' => 30,
      'connect_timeout' => 10,
    ];

    if ($data !== null) {
      $options['json'] = $data;
    }

    try {
      $response = $this->httpClient->request($method, $endpoints['api'] . $endpoint, $options);
      $body = $response->getBody()->getContents();
      
      if (empty($body)) {
        throw ApiException::invalidResponse('Empty response body');
      }
      
      $responseData = json_decode($body, true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw ApiException::malformedJson(json_last_error_msg());
      }
      
      return $responseData ?: [];
    }
    catch (RequestException $e) {
      $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      
      switch ($statusCode) {
        case 401:
          // Clear invalid token
          $this->accessToken = null;
          $this->tokenExpiry = 0;
          throw ApiException::authenticationFailed($e->getMessage());
          
        case 429:
          throw ApiException::apiRateLimited();
          
        case 503:
          throw ApiException::serviceUnavailable();
          
        default:
          throw ApiException::httpError($statusCode, $e->getMessage());
      }
    }
  }
}