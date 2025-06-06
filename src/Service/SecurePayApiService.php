<?php

namespace Drupal\webform_securepay\Service;

use Drupal\webform_securepay\Exception\PaymentException;
use Drupal\webform_securepay\ValueObject\PaymentRequest;
use Drupal\webform_securepay\ValueObject\PaymentResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * SecurePay API service.
 */
class SecurePayApiService {

  private ?string $accessToken = null;
  private int $tokenExpiry = 0;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigurationService $config,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Process payment.
   */
  public function processPayment(PaymentRequest $request): PaymentResult {
    try {
      $response = $this->makeApiRequest('POST', '/v2/payments', $request->toArray());
      
      if (isset($response['transactionId'])) {
        return PaymentResult::success(
          $response['transactionId'],
          $response['status'] ?? 'completed',
          $response
        );
      }
      
      throw PaymentException::api('No transaction ID in response');
    }
    catch (\Exception $e) {
      $this->logger->error('Payment failed: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $request->getOrderId(),
      ]);
      
      return PaymentResult::failure($e->getMessage());
    }
  }

  /**
   * Get UI SDK URL.
   */
  public function getUiSdkUrl(): string {
    $endpoints = $this->config->getApiEndpoints();
    return $endpoints['ui_sdk'];
  }

  /**
   * Test API connection.
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
      throw PaymentException::configuration('Missing client credentials');
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
      ]);

      $data = json_decode($response->getBody()->getContents(), true);
      
      if (empty($data['access_token'])) {
        throw PaymentException::api('Invalid token response');
      }

      $this->accessToken = $data['access_token'];
      $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60;

      return $this->accessToken;
    }
    catch (RequestException $e) {
      throw PaymentException::api('Authentication failed: ' . $e->getMessage());
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
    ];

    if ($data) {
      $options['json'] = $data;
    }

    try {
      $response = $this->httpClient->request($method, $endpoints['api'] . $endpoint, $options);
      $responseData = json_decode($response->getBody()->getContents(), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw PaymentException::api('Invalid JSON response');
      }
      
      return $responseData ?: [];
    }
    catch (RequestException $e) {
      $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      throw PaymentException::api("Request failed ({$statusCode}): " . $e->getMessage(), $statusCode);
    }
  }
}