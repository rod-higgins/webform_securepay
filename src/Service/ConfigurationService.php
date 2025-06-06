<?php

namespace Drupal\webform_securepay\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for handling SecurePay configuration.
 */
class ConfigurationService {

  // Configuration keys
  public const CONFIG_NAME = 'webform_securepay.settings';

  // Authentication settings
  public const CLIENT_ID = 'client_id';
  public const CLIENT_SECRET = 'client_secret';
  public const MERCHANT_CODE = 'merchant_code';
  public const ENVIRONMENT = 'environment';

  // Payment settings
  public const CURRENCY = 'currency';
  public const DEFAULT_MODE = 'default_mode';
  public const ORDER_ID_PREFIX = 'order_id_prefix';
  public const TIMEOUT = 'timeout';

  // Feature flags
  public const DCC_ENABLED = 'dcc_enabled';
  public const THREE_DS_ENABLED = 'three_ds_enabled';
  public const FRAUD_GUARD_ENABLED = 'fraud_guard_enabled';
  public const AUTO_FOCUS = 'auto_focus';
  public const BIN_CHECK_ENABLED = 'bin_check_enabled';

  // UI settings
  public const ALLOWED_CARD_TYPES = 'allowed_card_types';
  public const SHOW_CARD_ICONS = 'show_card_icons';
  public const BACKGROUND_COLOR = 'background_color';
  public const LABEL_FONT_FAMILY = 'label_font_family';
  public const LABEL_FONT_SIZE = 'label_font_size';
  public const LABEL_COLOR = 'label_color';
  public const INPUT_FONT_FAMILY = 'input_font_family';
  public const INPUT_FONT_SIZE = 'input_font_size';
  public const INPUT_COLOR = 'input_color';

  // Advanced settings
  public const LOG_TRANSACTIONS = 'log_transactions';
  public const DEBUG_MODE = 'debug_mode';
  public const EMAIL_NOTIFICATIONS = 'email_notifications';
  public const NOTIFICATION_EMAIL = 'notification_email';

  // Default values
  public const DEFAULTS = [
    self::ENVIRONMENT => 'sandbox',
    self::CURRENCY => 'AUD',
    self::DEFAULT_MODE => 'checkout',
    self::ORDER_ID_PREFIX => 'WF_',
    self::TIMEOUT => 30,
    self::DCC_ENABLED => FALSE,
    self::THREE_DS_ENABLED => FALSE,
    self::FRAUD_GUARD_ENABLED => FALSE,
    self::AUTO_FOCUS => FALSE,
    self::BIN_CHECK_ENABLED => FALSE,
    self::ALLOWED_CARD_TYPES => ['visa', 'mastercard', 'amex', 'diners'],
    self::SHOW_CARD_ICONS => TRUE,
    self::BACKGROUND_COLOR => 'rgba(255, 255, 255, 0.1)',
    self::LABEL_FONT_FAMILY => 'Arial, Helvetica, sans-serif',
    self::LABEL_FONT_SIZE => '1rem',
    self::LABEL_COLOR => '#333',
    self::INPUT_FONT_FAMILY => 'Arial, Helvetica, sans-serif',
    self::INPUT_font_SIZE => '1rem',
    self::INPUT_COLOR => '#333',
    self::LOG_TRANSACTIONS => FALSE,
    self::DEBUG_MODE => FALSE,
    self::EMAIL_NOTIFICATIONS => FALSE,
  ];

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a ConfigurationService object.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get a configuration value.
   *
   * @param string $key
   *   The configuration key.
   * @param mixed $default
   *   The default value if not set.
   *
   * @return mixed
   *   The configuration value.
   */
  public function get(string $key, $default = NULL) {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    return $config->get($key) ?? $default ?? self::DEFAULTS[$key] ?? NULL;
  }

  /**
   * Set a configuration value.
   *
   * @param string $key
   *   The configuration key.
   * @param mixed $value
   *   The value to set.
   */
  public function set(string $key, $value): void {
    $config = $this->configFactory->getEditable(self::CONFIG_NAME);
    $config->set($key, $value)->save();
  }

  /**
   * Get multiple configuration values.
   *
   * @param array $keys
   *   Array of configuration keys.
   *
   * @return array
   *   Array of configuration values keyed by key.
   */
  public function getMultiple(array $keys): array {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $values = [];

    foreach ($keys as $key) {
      $values[$key] = $config->get($key) ?? self::DEFAULTS[$key] ?? NULL;
    }

    return $values;
  }

  /**
   * Get authentication settings.
   *
   * @return array
   *   Authentication configuration array.
   */
  public function getAuthSettings(): array {
    return $this->getMultiple([
      self::CLIENT_ID,
      self::CLIENT_SECRET,
      self::MERCHANT_CODE,
      self::ENVIRONMENT,
    ]);
  }

  /**
   * Get payment settings.
   *
   * @return array
   *   Payment configuration array.
   */
  public function getPaymentSettings(): array {
    return $this->getMultiple([
      self::CURRENCY,
      self::DEFAULT_MODE,
      self::ORDER_ID_PREFIX,
      self::TIMEOUT,
    ]);
  }

  /**
   * Get UI settings.
   *
   * @return array
   *   UI configuration array.
   */
  public function getUiSettings(): array {
    return $this->getMultiple([
      self::ALLOWED_CARD_TYPES,
      self::SHOW_CARD_ICONS,
      self::BACKGROUND_COLOR,
      self::LABEL_FONT_FAMILY,
      self::LABEL_FONT_SIZE,
      self::LABEL_COLOR,
      self::INPUT_FONT_FAMILY,
      self::INPUT_FONT_SIZE,
      self::INPUT_COLOR,
    ]);
  }

  /**
   * Get feature flags.
   *
   * @return array
   *   Feature flags array.
   */
  public function getFeatureFlags(): array {
    return $this->getMultiple([
      self::DCC_ENABLED,
      self::THREE_DS_ENABLED,
      self::FRAUD_GUARD_ENABLED,
      self::AUTO_FOCUS,
      self::BIN_CHECK_ENABLED,
    ]);
  }

  /**
   * Check if module is properly configured.
   *
   * @return bool
   *   TRUE if configured, FALSE otherwise.
   */
  public function isConfigured(): bool {
    $auth_settings = $this->getAuthSettings();
    return !empty($auth_settings[self::CLIENT_ID]) &&
           !empty($auth_settings[self::CLIENT_SECRET]) &&
           !empty($auth_settings[self::MERCHANT_CODE]);
  }

  /**
   * Check if in live environment.
   *
   * @return bool
   *   TRUE if live environment, FALSE otherwise.
   */
  public function isLiveEnvironment(): bool {
    return $this->get(self::ENVIRONMENT) === 'live';
  }

  /**
   * Check if debug mode is enabled.
   *
   * @return bool
   *   TRUE if debug mode enabled, FALSE otherwise.
   */
  public function isDebugMode(): bool {
    return (bool) $this->get(self::DEBUG_MODE);
  }

  /**
   * Merge element settings with global configuration.
   *
   * @param array $element_settings
   *   Element-specific settings.
   * @param array $keys
   *   Configuration keys to merge.
   *
   * @return array
   *   Merged settings array.
   */
  public function mergeWithElementSettings(array $element_settings, array $keys): array {
    $settings = [];

    foreach ($keys as $key) {
      $settings[$key] = $element_settings[$key] ?? $this->get($key);
    }

    return $settings;
  }

  /**
   * Validate required authentication settings.
   *
   * @throws \InvalidArgumentException
   *   When required settings are missing.
   */
  public function validateAuthSettings(): void {
    $auth_settings = $this->getAuthSettings();

    if (empty($auth_settings[self::CLIENT_ID])) {
      throw new \InvalidArgumentException('Client ID is required');
    }

    if (empty($auth_settings[self::CLIENT_SECRET])) {
      throw new \InvalidArgumentException('Client Secret is required');
    }

    if (empty($auth_settings[self::MERCHANT_CODE])) {
      throw new \InvalidArgumentException('Merchant Code is required');
    }
  }

  /**
   * Get all configuration as array.
   *
   * @return array
   *   Complete configuration array.
   */
  public function getAll(): array {
    return $this->configFactory->get(self::CONFIG_NAME)->getRawData();
  }

}