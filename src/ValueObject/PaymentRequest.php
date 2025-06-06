<?php

namespace Drupal\webform_securepay\ValueObject;

use Drupal\webform_securepay\Exception\PaymentException;

/**
 * Payment request value object.
 */
class PaymentRequest {

  public function __construct(
    public string $token,
    public int $amount,
    public string $currency,
    public string $merchantCode,
    public string $orderId,
    public ?string $ipAddress = null,
    public ?string $userAgent = null,
    public ?array $dccQuote = null,
    public ?array $threeDSResult = null,
  ) {
    if ($amount <= 0) {
      throw new PaymentException('Amount must be greater than zero');
    }
    
    if (empty(trim($token))) {
      throw new PaymentException('Payment token is required');
    }
  }

  public function toArray(): array {
    return [
      'token' => $this->token,
      'amount' => $this->amount,
      'currency' => $this->currency,
      'merchantCode' => $this->merchantCode,
      'orderId' => $this->orderId,
      'ip' => $this->ipAddress,
      'userAgent' => $this->userAgent,
      'dccQuote' => $this->dccQuote,
      'threeDSResult' => $this->threeDSResult,
    ];
  }
}

/**
 * Payment result value object.
 */
readonly class PaymentResult {

  public function __construct(
    public bool $success,
    public string $transactionId,
    public string $status,
    public int $amount,
    public string $currency,
    public ?string $gatewayResponseCode = null,
    public ?string $gatewayResponseMessage = null,
    public ?string $bankTransactionId = null,
    public ?array $rawResponse = null,
    public ?string $error = null,
    public ?string $errorCode = null,
  ) {}

  public static function fromApiResponse(array $response): self {
    $success = ($response['status'] ?? '') === 'paid';
    
    return new self(
      success: $success,
      transactionId: $response['orderId'] ?? '',
      status: $response['status'] ?? 'unknown',
      amount: (int) ($response['amount'] ?? 0),
      currency: $response['currency'] ?? 'AUD',
      gatewayResponseCode: $response['gatewayResponseCode'] ?? null,
      gatewayResponseMessage: $response['gatewayResponseMessage'] ?? null,
      bankTransactionId: $response['bankTransactionId'] ?? null,
      rawResponse: $response,
      error: $success ? null : ($response['error'] ?? 'Payment failed'),
      errorCode: $success ? null : ($response['errorCode'] ?? null),
    );
  }

  public function toArray(): array {
    return [
      'success' => $this->success,
      'transaction_id' => $this->transactionId,
      'status' => $this->status,
      'amount' => $this->amount,
      'currency' => $this->currency,
      'gateway_response_code' => $this->gatewayResponseCode,
      'gateway_response_message' => $this->gatewayResponseMessage,
      'bank_transaction_id' => $this->bankTransactionId,
      'error' => $this->error,
      'error_code' => $this->errorCode,
      'created_at' => date('c'),
    ];
  }
}

/**
 * Configuration value object.
 */
readonly class SecurePayConfig {

  public function __construct(
    public string $clientId,
    public string $clientSecret,
    public string $merchantCode,
    public string $environment,
    public string $currency = 'AUD',
    public string $orderIdPrefix = 'WF_',
    public int $timeout = 30,
    public array $allowedCardTypes = ['visa', 'mastercard', 'amex', 'diners'],
    public bool $dccEnabled = false,
    public bool $threeDSEnabled = false,
    public bool $fraudGuardEnabled = false,
    public bool $logTransactions = false,
    public bool $debugMode = false,
  ) {
    if (empty(trim($clientId))) {
      throw new PaymentException('Client ID is required');
    }
    
    if (empty(trim($clientSecret))) {
      throw new PaymentException('Client Secret is required');
    }
    
    if (empty(trim($merchantCode))) {
      throw new PaymentException('Merchant Code is required');
    }
    
    if (!in_array($environment, ['sandbox', 'live'])) {
      throw new PaymentException('Environment must be sandbox or live');
    }
  }

  public function isLive(): bool {
    return $this->environment === 'live';
  }

  public function getApiEndpoints(): array {
    $endpoints = [
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

    return $endpoints[$this->environment];
  }
}