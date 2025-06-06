<?php

namespace Drupal\webform_securepay\Service;

use Drupal\webform_securepay\Exception\ApiException;
use Drupal\webform_securepay\Exception\ConfigurationException;
use Drupal\webform_securepay\ValueObject\PaymentRequest;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * SecurePay API service.
 */
class SecurePayApiService implements SecurePayApiServiceInterface {

  // API constants
  private const TOKEN_EXPIRY_BUFFER = 60; // seconds
  private const DEFAULT_TOKEN_EXPIRY = 3600; // seconds
  private const REQUEST_TIMEOUT = 30; // seconds
  private const CONNECT_TIMEOUT = 10; // seconds
  private const MIN_TOKEN_LENGTH = 10;
  private const TOKEN_PATTERN = '/^[a-zA-Z0-9_-]+$/';
  
  // API endpoints
  private const ENDPOINT_PAYMENTS = '/v2/payments';
  private const ENDPOINT_ORDERS = '/v2/orders';
  private const ENDPOINT_REFUNDS = '/v2/refunds';
  
  // Order types
  private const ORDER_TYPE_DCC = 'DYNAMIC_CURRENCY_CONVERSION';
  private const ORDER_TYPE_3DS = 'THREED_SECURE';

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
        'token' => $this->sanitizeToken($payment_data['token'] ?? ''),
        'amount' => (int) ($payment_data['amount'] ?? 0),
        'currency' => $this->sanitizeCurrency($payment_data['currency'] ?? $this->config->get('currency') ?? ConfigurationService::CURRENCY_AUD),
        'merchantCode' => $this->config->getMerchantCode(),
        'orderId' => $payment_data['orderId'] ?? $this->generateOrderId(),
        'ipAddress' => $this->sanitizeIpAddress($payment_data['ipAddress'] ?? null),
      ]);

      $response = $this->makeApiRequest('POST', self::ENDPOINT_PAYMENTS, $request->toArray());
      
      if (isset($response['transactionId'])) {
        return [
          'success' => true,
          'transaction_id' => $this->sanitizeTransactionId($response['transactionId']),
          'status' => $this->sanitizeStatus($response['status'] ?? 'completed'),
          'raw_response' => $this->sanitizeRawResponse($response),
        ];
      }
      
      throw ApiException::invalidResponse('No transaction ID in response');
    }
    catch (ApiException $e) {
      $this->logger->error('API payment failed: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $payment_data['orderId'] ?? 'unknown',
        'error_code' => $e->getErrorCode(),
      ]);
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Payment failed: {message}', [
        'message' => $e->getMessage(),
        'order_id' => $payment_data['orderId'] ?? 'unknown',
      ]);
      
      return [
        'success' => false,
        'error' => 'Payment processing failed',
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function initiatePaymentOrder(int $amount, string $order_type = self::ORDER_TYPE_DCC, ?string $order_reference = null): array|false {
    if (!$this->isValidOrderType($order_type)) {
      $this->logger->error('Invalid order type: {type}', ['type' => $order_type]);
      return false;
    }

    try {
      $data = [
        'amount' => max(1, $amount),
        'orderType' => $order_type,
        'merchantCode' => $this->config->getMerchantCode(),
      ];

      if ($order_reference !== null) {
        $data['orderReference'] = $this->sanitizeOrderReference($order_reference);
      }

      $response = $this->makeApiRequest('POST', self::ENDPOINT_ORDERS, $data);
      return is_array($response) ? $response : false;
    }
    catch (\Exception $e) {
      $this->logger->error('Order initiation failed: {message}', [
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
    if (empty(trim($order_id)) || $amount <= 0) {
      $this->logger->error('Invalid refund parameters: order_id={order_id}, amount={amount}', [
        'order_id' => $order_id,
        'amount' => $amount,
      ]);
      return false;
    }

    try {
      $data = [
        'orderId' => $this->sanitizeOrderId($order_id),
        'amount' => max(1, $amount),
        'merchantCode' => $merchant_code ?? $this->config->getMerchantCode(),
      ];

      $response = $this->makeApiRequest('POST', self::ENDPOINT_REFUNDS, $data);
      return is_array($response) ? $response : false;
    }
    catch (\Exception $e) {
      $this->logger->error('Refund failed: {message}', [
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
    $isLive = $this->config->get('environment') === ConfigurationService::ENVIRONMENT_LIVE;
    $baseUrl = $isLive 
      ? 'https://api.payments.auspost.com.au'
      : 'https://api.payments.test.auspost.com.au';
    
    return $baseUrl . '/3ds2/v1/sdk/threeds2.min.js';
  }

  /**
   * Generate unique order ID.
   */
  private function generateOrderId(): string {
    return 'WF_' . time() . '_' . substr(hash('sha256', uniqid(mt_rand(), true)), 0, 8);
  }

  /**
   * Get or refresh access token.
   */
  private function getAccessToken(): string {
    if ($this->accessToken && time() < $this->tokenExpiry) {
      return $this->accessToken;
    }

    $endpoints = $this->config->getApiEndpoints();
    $clientId = $this->config->getClientId();
    $clientSecret = $this->config->getClientSecret();

    if (empty($clientId) || empty($clientSecret)) {
      throw ConfigurationException::missingConfiguration();
    }

    try {
      $response = $this->httpClient->post($endpoints['auth'], [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
          'Content-Type' => 'application/x-www-form-urlencoded',
          'User-Agent' => 'Drupal-WebformSecurePay/1.0',
        ],
        'form_params' => [
          'grant_type' => 'client_credentials',
          'audience' => 'https://api.payments.auspost.com.au',
        ],
        'timeout' => self::REQUEST_TIMEOUT,
        'connect_timeout' => self::CONNECT_TIMEOUT,
      ]);

      $data = json_decode($response->getBody()->getContents(), true);
      
      if (empty($data['access_token'])) {
        throw ApiException::invalidResponse('Invalid token response');
      }

      $this->accessToken = $data['access_token'];
      $this->tokenExpiry = time() + ($data['expires_in'] ?? self::DEFAULT_TOKEN_EXPIRY) - self::TOKEN_EXPIRY_BUFFER;

      return $this->accessToken;
    }
    catch (RequestException $e) {
      $this->accessToken = null;
      $this->tokenExpiry = 0;
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
        'User-Agent' => 'Drupal-WebformSecurePay/1.0',
      ],
      'timeout' => self::REQUEST_TIMEOUT,
      'connect_timeout' => self::CONNECT_TIMEOUT,
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

  /**
   * Sanitize payment token.
   */
  private function sanitizeToken(string $token): string {
    $token = trim($token);
    if (strlen($token) < self::MIN_TOKEN_LENGTH || !preg_match(self::TOKEN_PATTERN, $token)) {
      throw ApiException::invalidResponse('Invalid token format');
    }
    return $token;
  }

  /**
   * Sanitize currency code.
   */
  private function sanitizeCurrency(string $currency): string {
    $currency = strtoupper(trim($currency));
    if (!$this->config->isValidCurrency($currency)) {
      throw ApiException::invalidResponse('Invalid currency');
    }
    return $currency;
  }

  /**
   * Sanitize IP address.
   */
  private function sanitizeIpAddress(?string $ipAddress): ?string {
    if ($ipAddress === null) {
      return null;
    }
    
    $ip = trim($ipAddress);
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
      return $ip;
    }
    
    return null;
  }

  /**
   * Sanitize transaction ID.
   */
  private function sanitizeTransactionId(string $transactionId): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', trim($transactionId));
  }

  /**
   * Sanitize status.
   */
  private function sanitizeStatus(string $status): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', trim($status));
  }

  /**
   * Sanitize order ID.
   */
  private function sanitizeOrderId(string $orderId): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', trim($orderId));
  }

  /**
   * Sanitize order reference.
   */
  private function sanitizeOrderReference(string $orderReference): string {
    return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($orderReference)), 0, 255);
  }

  /**
   * Sanitize raw response data.
   */
  private function sanitizeRawResponse(array $response): array {
    // Remove sensitive data from logs
    $sanitized = $response;
    unset($sanitized['cardNumber'], $sanitized['cvv'], $sanitized['expiryDate']);
    return $sanitized;
  }

  /**
   * Check if order type is valid.
   */
  private function isValidOrderType(string $orderType): bool {
    return in_array($orderType, [self::ORDER_TYPE_DCC, self::ORDER_TYPE_3DS], true);
  }
}