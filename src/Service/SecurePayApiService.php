<?php

namespace Drupal\webform_securepay\Service;

use Drupal\webform_securepay\Exception\ApiException;
use Drupal\webform_securepay\ValueObject\SecurePayConfig;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Refactored SecurePay API service.
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

  public function processPayment(array $payment_data, array $element_settings = []): array {
    $config = $this->configService->getSecurePayConfig();
    $request = $this->buildPaymentRequest($payment_data, $config);
    
    try {
      return $this->makeApiRequest('POST', '/v2/payments', $request);
    }
    catch (\Exception $e) {
      throw new ApiException("Payment processing failed: {$e->getMessage()}", 0, $e);
    }
  }

  public function initiatePaymentOrder(int $amount, string $order_type = 'DYNAMIC_CURRENCY_CONVERSION', ?string $order_reference = null): array|false {
    $config = $this->configService->getSecurePayConfig();
    
    $request = [
      'merchantCode' => $config->merchantCode,
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
      ]);
      return false;
    }
  }

  public function refundPayment(string $order_id, int $amount, ?string $merchant_code = null): array|false {
    $config = $this->configService->getSecurePayConfig();
    
    $request = [
      'merchantCode' => $merchant_code ?: $config->merchantCode,
      'amount' => $amount,
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ];

    try {
      return $this->makeApiRequest('POST', "/v2/orders/{$order_id}/refunds", $request);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process refund: {message}', [
        'message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  public function testConnection(): bool {
    try {
      $result = $this->makeApiRequest('GET', '/v2/health');
      return ($result['status'] ?? '') === 'UP';
    }
    catch (\Exception $e) {
      $this->logger->error('Connection test failed: {message}', [
        'message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  public function getUiSdkUrl(): string {
    $config = $this->configService->getSecurePayConfig();
    $endpoints = $config->getApiEndpoints();
    return $endpoints['ui_sdk'];
  }

  public function getThreeDS2SdkUrl(): string {
    $config = $this->configService->getSecurePayConfig();
    $endpoints = $config->getApiEndpoints();
    return $endpoints['threeDS_sdk'];
  }

  private function getAccessToken(): string {
    if ($this->accessToken && time() < $this->tokenExpiry) {
      return $this->accessToken;
    }

    $config = $this->configService->getSecurePayConfig();
    $endpoints = $config->getApiEndpoints();

    try {
      $response = $this->httpClient->post($endpoints['auth'], [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($config->clientId . ':' . $config->clientSecret),
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => self::GRANT_TYPE,
          'audience' => self::AUDIENCE,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), true);
      
      if (empty($data['access_token'])) {
        throw new ApiException('Invalid token response from SecurePay');
      }

      $this->accessToken = $data['access_token'];
      $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - self::TOKEN_BUFFER_SECONDS;

      return $this->accessToken;
    }
    catch (RequestException $e) {
      throw new ApiException("Authentication failed: {$e->getMessage()}", 0, $e);
    }
  }

  private function makeApiRequest(string $method, string $endpoint, ?array $data = null): array {
    $token = $this->getAccessToken();
    $config = $this->configService->getSecurePayConfig();
    $endpoints = $config->getApiEndpoints();

    $options = [
      'headers' => [
        'Authorization' => "Bearer {$token}",
        'Content-Type' => 'application/json',
      ],
      'timeout' => $config->timeout,
    ];

    if ($data) {
      $options['json'] = $data;
    }

    try {
      $response = $this->httpClient->request($method, $endpoints['api'] . $endpoint, $options);
      return json_decode($response->getBody()->getContents(), true) ?: [];
    }
    catch (RequestException $e) {
      throw new ApiException("API request failed: {$e->getMessage()}", 0, $e);
    }
  }

  private function buildPaymentRequest(array $payment_data, SecurePayConfig $config): array {
    return [
      'merchantCode' => $config->merchantCode,
      'amount' => $payment_data['amount'],
      'token' => $payment_data['token'],
      'ip' => $payment_data['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
      'orderId' => $payment_data['orderId'],
      'currency' => $payment_data['currency'] ?? $config->currency,
    ];
  }
}