<?php

namespace Drupal\webform_securepay\ValueObject;

use Drupal\webform_securepay\Exception\PaymentException;

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