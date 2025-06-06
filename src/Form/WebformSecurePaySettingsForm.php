<?php

namespace Drupal\webform_securepay\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Configure WebformSecurePay settings for this site.
 */
class WebformSecurePaySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_securepay_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['webform_securepay.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('webform_securepay.settings');

    $form['introduction'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure SecurePay payment processing for Drupal Webforms. This module uses the modern SecurePay REST API with OAuth 2.0 authentication.') . '</p>',
    ];

    $form['help_links'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Useful links: @api_docs | @account_setup | @support', [
        '@api_docs' => Link::fromTextAndUrl('API Documentation', Url::fromUri('https://auspost.com.au/payments/docs/securepay/'))->toString(),
        '@account_setup' => Link::fromTextAndUrl('Account Setup', Url::fromUri('https://auspost.com.au/payments/dashboard/'))->toString(),
        '@support' => Link::fromTextAndUrl('Support', Url::fromUri('https://www.securepay.com.au/support/contact-us/'))->toString(),
      ]) . '</p>',
    ];

    // Authentication Settings
    $form['authentication'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Authentication Settings'),
      '#description' => $this->t('These credentials are required for OAuth 2.0 authentication with the SecurePay REST API.'),
    ];

    $form['authentication']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#description' => $this->t('Your SecurePay Client ID from the merchant dashboard.'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['authentication']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->t('Your SecurePay Client Secret. Leave empty to keep current secret.'),
      '#maxlength' => 255,
    ];

    if ($config->get('client_secret')) {
      $form['authentication']['client_secret']['#description'] .= ' ' . $this->t('Current secret is set.');
    }

    $form['authentication']['merchant_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Code'),
      '#description' => $this->t('Your SecurePay Merchant Code.'),
      '#default_value' => $config->get('merchant_code'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['authentication']['environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#description' => $this->t('Select the SecurePay environment to use.'),
      '#options' => [
        'sandbox' => $this->t('Sandbox (Testing)'),
        'live' => $this->t('Live (Production)'),
      ],
      '#default_value' => $config->get('environment') ?: 'sandbox',
      '#required' => TRUE,
    ];

    // Payment Settings
    $form['payment'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Default Payment Settings'),
      '#description' => $this->t('These settings will be used as defaults for new SecurePay webform elements.'),
    ];

    $form['payment']['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Currency'),
      '#description' => $this->t('Default currency for payments.'),
      '#options' => [
        'AUD' => 'Australian Dollar (AUD)',
        'USD' => 'US Dollar (USD)',
        'EUR' => 'Euro (EUR)',
        'GBP' => 'British Pound (GBP)',
        'NZD' => 'New Zealand Dollar (NZD)',
        'CAD' => 'Canadian Dollar (CAD)',
        'JPY' => 'Japanese Yen (JPY)',
        'SGD' => 'Singapore Dollar (SGD)',
        'CHF' => 'Swiss Franc (CHF)',
        'NOK' => 'Norwegian Krone (NOK)',
        'MYR' => 'Malaysian Ringgit (MYR)',
        'HKD' => 'Hong Kong Dollar (HKD)',
      ],
      '#default_value' => $config->get('currency') ?: 'AUD',
      '#required' => TRUE,
    ];

    $form['payment']['default_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Payment Mode'),
      '#description' => $this->t('Default mode for payment processing.'),
      '#options' => [
        'checkout' => $this->t('Checkout (Standard)'),
        'dcc' => $this->t('DCC (Dynamic Currency Conversion)'),
      ],
      '#default_value' => $config->get('default_mode') ?: 'checkout',
    ];

    $form['payment']['order_id_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Order ID Prefix'),
      '#description' => $this->t('Default prefix for order IDs to ensure uniqueness.'),
      '#default_value' => $config->get('order_id_prefix') ?: 'WF_',
      '#maxlength' => 10,
    ];

    // Card Settings
    $form['card'] = [
      '#type' => 'details',
      '#title' => $this->t('Card Settings'),
      '#open' => FALSE,
    ];

    $form['card']['allowed_card_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Default Allowed Card Types'),
      '#description' => $this->t('Select which card types to accept by default.'),
      '#options' => [
        'visa' => $this->t('Visa'),
        'mastercard' => $this->t('Mastercard'),
        'amex' => $this->t('American Express'),
        'diners' => $this->t('Diners Club'),
      ],
      '#default_value' => $config->get('allowed_card_types') ?: ['visa', 'mastercard'],
    ];

    $form['card']['show_card_icons'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Card Icons by Default'),
      '#description' => $this->t('Display card type icons in payment forms by default.'),
      '#default_value' => $config->get('show_card_icons') ?? TRUE,
    ];

    // UI Style Settings
    $form['style'] = [
      '#type' => 'details',
      '#title' => $this->t('UI Style Settings'),
      '#description' => $this->t('Customize the appearance of payment forms.'),
      '#open' => FALSE,
    ];

    $form['style']['background_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Background Color'),
      '#description' => $this->t('Default background color for payment forms (e.g., rgba(255, 255, 255, 0.1), #ffffff).'),
      '#default_value' => $config->get('background_color') ?: 'rgba(255, 255, 255, 0.1)',
    ];

    $form['style']['label_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Label Settings'),
    ];

    $form['style']['label_settings']['label_font_family'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label Font Family'),
      '#description' => $this->t('Default font family for form labels.'),
      '#default_value' => $config->get('label_font_family') ?: 'Arial, Helvetica, sans-serif',
    ];

    $form['style']['label_settings']['label_font_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label Font Size'),
      '#description' => $this->t('Default font size for form labels (e.g., 1rem, 14px).'),
      '#default_value' => $config->get('label_font_size') ?: '1rem',
    ];

    $form['style']['label_settings']['label_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label Color'),
      '#description' => $this->t('Default color for form labels.'),
      '#default_value' => $config->get('label_color') ?: '#333',
    ];

    $form['style']['input_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Input Settings'),
    ];

    $form['style']['input_settings']['input_font_family'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Input Font Family'),
      '#description' => $this->t('Default font family for form inputs.'),
      '#default_value' => $config->get('input_font_family') ?: 'Arial, Helvetica, sans-serif',
    ];

    $form['style']['input_settings']['input_font_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Input Font Size'),
      '#description' => $this->t('Default font size for form inputs (e.g., 1rem, 14px).'),
      '#default_value' => $config->get('input_font_size') ?: '1rem',
    ];

    $form['style']['input_settings']['input_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Input Color'),
      '#description' => $this->t('Default color for form inputs.'),
      '#default_value' => $config->get('input_color') ?: '#333',
    ];

    // Feature Settings
    $form['features'] = [
      '#type' => 'details',
      '#title' => $this->t('Feature Settings'),
      '#description' => $this->t('Enable or disable advanced payment features.'),
      '#open' => FALSE,
    ];

    $form['features']['dcc_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Dynamic Currency Conversion by Default'),
      '#description' => $this->t('Allow customers to pay in their card currency by default.'),
      '#default_value' => $config->get('dcc_enabled') ?? FALSE,
    ];

    $form['features']['three_ds_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable 3D Secure 2 by Default'),
      '#description' => $this->t('Enable 3D Secure 2 authentication for enhanced security by default.'),
      '#default_value' => $config->get('three_ds_enabled') ?? FALSE,
    ];

    $form['features']['fraud_guard_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable FraudGuard by Default'),
      '#description' => $this->t('Enable fraud detection before processing payments by default.'),
      '#default_value' => $config->get('fraud_guard_enabled') ?? FALSE,
    ];

    $form['features']['auto_focus'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto Focus by Default'),
      '#description' => $this->t('Automatically focus on the first field when forms load.'),
      '#default_value' => $config->get('auto_focus') ?? FALSE,
    ];

    $form['features']['bin_check_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable BIN Check by Default'),
      '#description' => $this->t('Enable Bank Identification Number checking for additional validation.'),
      '#default_value' => $config->get('bin_check_enabled') ?? FALSE,
    ];

    // Advanced Settings
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout'),
      '#description' => $this->t('Timeout for SecurePay API requests in seconds.'),
      '#default_value' => $config->get('timeout') ?: 30,
      '#min' => 5,
      '#max' => 300,
      '#step' => 1,
    ];

    $form['advanced']['log_transactions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log Transactions'),
      '#description' => $this->t('Log all SecurePay transactions for debugging purposes.'),
      '#default_value' => $config->get('log_transactions') ?? FALSE,
    ];

    $form['advanced']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug Mode'),
      '#description' => $this->t('Enable additional debugging information. <strong>Do not enable in production!</strong>'),
      '#default_value' => $config->get('debug_mode') ?? FALSE,
    ];

    // Fraud Settings
    $form['fraud'] = [
      '#type' => 'details',
      '#title' => $this->t('Fraud Detection Settings'),
      '#open' => FALSE,
    ];

    $form['fraud']['fraud_check_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Fraud Check Type'),
      '#description' => $this->t('Select the fraud detection service to use.'),
      '#options' => [
        '' => $this->t('- None -'),
        'FRAUD_GUARD' => $this->t('FraudGuard'),
        'ACI_FRAUD_CHECK' => $this->t('ACI ReD Shield'),
      ],
      '#default_value' => $config->get('fraud_check_type') ?: '',
    ];

    $form['fraud']['fraud_score_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Fraud Score Threshold'),
      '#description' => $this->t('Payments with fraud scores above this threshold will be blocked.'),
      '#default_value' => $config->get('fraud_score_threshold') ?: 75,
      '#min' => 0,
      '#max' => 100,
    ];

    // Notification Settings
    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Notification Settings'),
      '#open' => FALSE,
    ];

    $form['notifications']['success_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Success Message'),
      '#description' => $this->t('Message displayed when payment is successful.'),
      '#default_value' => $config->get('success_message') ?: $this->t('Payment processed successfully.'),
    ];

    $form['notifications']['error_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Error Message'),
      '#description' => $this->t('Message displayed when payment fails.'),
      '#default_value' => $config->get('error_message') ?: $this->t('Payment processing failed. Please try again.'),
    ];

    $form['notifications']['email_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Email Notifications'),
      '#description' => $this->t('Send email notifications for payment events.'),
      '#default_value' => $config->get('email_notifications') ?? FALSE,
    ];

    $form['notifications']['notification_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Notification Email Address'),
      '#description' => $this->t('Email address to receive payment notifications.'),
      '#default_value' => $config->get('notification_email') ?: '',
      '#states' => [
        'visible' => [
          ':input[name="email_notifications"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Custom Code
    $form['custom'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom Code'),
      '#open' => FALSE,
    ];

    $form['custom']['custom_css'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom CSS'),
      '#description' => $this->t('Additional CSS to customize payment form appearance.'),
      '#default_value' => $config->get('custom_css') ?: '',
      '#rows' => 10,
    ];

    $form['custom']['custom_javascript'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom JavaScript'),
      '#description' => $this->t('Additional JavaScript code for custom event handling.'),
      '#default_value' => $config->get('custom_javascript') ?: '',
      '#rows' => 10,
    ];

    // Test Connection
    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => FALSE,
    ];

    $form['test']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test SecurePay Connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::testConnectionAjax',
        'wrapper' => 'test-connection-result',
      ],
    ];

    $form['test']['test_result'] = [
      '#type' => 'markup',
      '#prefix' => '<div id="test-connection-result">',
      '#suffix' => '</div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Test connection submit handler.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    // This will be handled by the AJAX callback
  }

  /**
   * Test connection AJAX callback.
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state) {
    try {
      $securepay_api = \Drupal::service('webform_securepay.api');
      if ($securepay_api->testConnection()) {
        $message = $this->t('✅ Connection successful! SecurePay API is accessible.');
        $class = 'messages messages--status';
      } else {
        $message = $this->t('❌ Connection failed. Please check your credentials and settings.');
        $class = 'messages messages--error';
      }
    } catch (\Exception $e) {
      $message = $this->t('❌ Connection failed: @error', ['@error' => $e->getMessage()]);
      $class = 'messages messages--error';
    }

    return [
      '#type' => 'markup',
      '#markup' => '<div class="' . $class . '">' . $message . '</div>',
      '#prefix' => '<div id="test-connection-result">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate client credentials
    $client_id = $form_state->getValue('client_id');
    if (!empty($client_id) && !preg_match('/^[A-Za-z0-9]+$/', $client_id)) {
      $form_state->setErrorByName('client_id', $this->t('Client ID should contain only letters and numbers.'));
    }

    $merchant_code = $form_state->getValue('merchant_code');
    if (!empty($merchant_code) && !preg_match('/^[A-Z0-9]+$/', $merchant_code)) {
      $form_state->setErrorByName('merchant_code', $this->t('Merchant Code should contain only uppercase letters and numbers.'));
    }

    // Validate card types
    $card_types = array_filter($form_state->getValue('allowed_card_types') ?: []);
    if (empty($card_types)) {
      $form_state->setErrorByName('allowed_card_types', $this->t('At least one card type must be selected.'));
    }

    // Validate email if notifications are enabled
    if ($form_state->getValue('email_notifications') && empty($form_state->getValue('notification_email'))) {
      $form_state->setErrorByName('notification_email', $this->t('Notification email is required when email notifications are enabled.'));
    }

    // Validate fraud settings
    $fraud_score = $form_state->getValue('fraud_score_threshold');
    if (!empty($fraud_score) && ($fraud_score < 0 || $fraud_score > 100)) {
      $form_state->setErrorByName('fraud_score_threshold', $this->t('Fraud score threshold must be between 0 and 100.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('webform_securepay.settings');
    
    // Authentication settings
    $config->set('client_id', $form_state->getValue('client_id'));
    $config->set('merchant_code', $form_state->getValue('merchant_code'));
    $config->set('environment', $form_state->getValue('environment'));
    
    // Only update client secret if a new one was provided
    $client_secret = $form_state->getValue('client_secret');
    if (!empty($client_secret)) {
      $config->set('client_secret', $client_secret);
    }
    
    // Payment settings
    $config->set('currency', $form_state->getValue('currency'));
    $config->set('default_mode', $form_state->getValue('default_mode'));
    $config->set('order_id_prefix', $form_state->getValue('order_id_prefix'));
    
    // Card settings
    $config->set('allowed_card_types', array_values(array_filter($form_state->getValue('allowed_card_types'))));
    $config->set('show_card_icons', $form_state->getValue('show_card_icons'));
    
    // Style settings
    $config->set('background_color', $form_state->getValue('background_color'));
    $config->set('label_font_family', $form_state->getValue('label_font_family'));
    $config->set('label_font_size', $form_state->getValue('label_font_size'));
    $config->set('label_color', $form_state->getValue('label_color'));
    $config->set('input_font_family', $form_state->getValue('input_font_family'));
    $config->set('input_font_size', $form_state->getValue('input_font_size'));
    $config->set('input_color', $form_state->getValue('input_color'));
    
    // Feature settings
    $config->set('dcc_enabled', $form_state->getValue('dcc_enabled'));
    $config->set('three_ds_enabled', $form_state->getValue('three_ds_enabled'));
    $config->set('fraud_guard_enabled', $form_state->getValue('fraud_guard_enabled'));
    $config->set('auto_focus', $form_state->getValue('auto_focus'));
    $config->set('bin_check_enabled', $form_state->getValue('bin_check_enabled'));
    
    // Advanced settings
    $config->set('timeout', $form_state->getValue('timeout'));
    $config->set('log_transactions', $form_state->getValue('log_transactions'));
    $config->set('debug_mode', $form_state->getValue('debug_mode'));
    
    // Fraud settings
    $config->set('fraud_check_type', $form_state->getValue('fraud_check_type'));
    $config->set('fraud_score_threshold', $form_state->getValue('fraud_score_threshold'));
    
    // Notification settings
    $config->set('success_message', $form_state->getValue('success_message'));
    $config->set('error_message', $form_state->getValue('error_message'));
    $config->set('email_notifications', $form_state->getValue('email_notifications'));
    $config->set('notification_email', $form_state->getValue('notification_email'));
    
    // Custom code
    $config->set('custom_css', $form_state->getValue('custom_css'));
    $config->set('custom_javascript', $form_state->getValue('custom_javascript'));
    
    $config->save();
    
    parent::submitForm($form, $form_state);
  }

}