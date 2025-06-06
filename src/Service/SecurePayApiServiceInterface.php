<?php

namespace Drupal\webform_securepay\Service;

/**
 * Interface for SecurePay API service.
 */
interface SecurePayApiServiceInterface {

  /**
   * Process a payment transaction.
   *
   * @param array $payment_data
   *   Payment data including token, amount, currency, etc.
   * @param array $element_settings
   *   Additional element settings.
   *
   * @return array
   *   Payment result data.
   *
   * @throws \Drupal\webform_securepay\Exception\ApiException
   */
  public function processPayment(array $payment_data, array $element_settings = []): array;

  /**
   * Initiate a payment order for DCC or 3DS2.
   *
   * @param int $amount
   *   Payment amount in cents.
   * @param string $order_type
   *   Order type (e.g., 'DYNAMIC_CURRENCY_CONVERSION', 'THREED_SECURE').
   * @param string|null $order_reference
   *   Optional order reference.
   *
   * @return array|false
   *   Order data or false on failure.
   */
  public function initiatePaymentOrder(int $amount, string $order_type = 'DYNAMIC_CURRENCY_CONVERSION', ?string $order_reference = null): array|false;

  /**
   * Process a refund for a transaction.
   *
   * @param string $order_id
   *   The original order ID.
   * @param int $amount
   *   Refund amount in cents.
   * @param string|null $merchant_code
   *   Optional merchant code override.
   *
   * @return array|false
   *   Refund result or false on failure.
   */
  public function refundPayment(string $order_id, int $amount, ?string $merchant_code = null): array|false;

  /**
   * Test connection to SecurePay API.
   *
   * @return bool
   *   True if connection is successful.
   */
  public function testConnection(): bool;

  /**
   * Get the URL for the SecurePay UI SDK.
   *
   * @return string
   *   SDK URL based on environment.
   */
  public function getUiSdkUrl(): string;

  /**
   * Get the URL for the 3D Secure 2 SDK.
   *
   * @return string
   *   3DS2 SDK URL based on environment.
   */
  public function getThreeDS2SdkUrl(): string;
}