<?php

namespace Drupal\webform_securepay\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a webform element for SecurePay integration.
 *
 * @FormElement("webform_securepay")
 */
class WebformSecurePay extends FormElement {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#process' => [
        [$class, 'processWebformSecurePay'],
        [$class, 'processAjaxForm'],
      ],
      '#element_validate' => [
        [$class, 'validateWebformSecurePay'],
      ],
      '#theme_wrappers' => ['container'],
      '#attached' => [
        'library' => ['webform_securepay/webform_securepay'],
      ],
    ];
  }

  /**
   * Processes a SecurePay element.
   */
  public static function processWebformSecurePay(&$element, FormStateInterface $form_state, &$complete_form) {
    $element['#tree'] = TRUE;
    
    // Get SecurePay API service
    $securepay_api = \Drupal::service('webform_securepay.api');
    $config = \Drupal::config('webform_securepay.settings');
    
    // Generate unique container ID
    $container_id = 'securepay-ui-container-' . $element['#name'];
    
    // Prepare settings for JavaScript
    $settings = [
      'clientId' => $element['#client_id'] ?? $config->get('client_id'),
      'merchantCode' => $element['#merchant_code'] ?? $config->get('merchant_code'),
      'environment' => $element['#environment'] ?? $config->get('environment', 'sandbox'),
      'amount' => $element['#amount'] ?? '',
      'currency' => $element['#currency'] ?? 'AUD',
      'mode' => $element['#mode'] ?? 'checkout',
      'allowedCardTypes' => array_values(array_filter($element['#allowed_card_types'] ?? ['visa', 'mastercard', 'amex', 'diners'])),
      'showCardIcons' => $element['#show_card_icons'] ?? TRUE,
      
      // Style settings
      'backgroundColor' => $element['#background_color'] ?? 'rgba(255, 255, 255, 0.1)',
      'labelFontFamily' => $element['#label_font_family'] ?? 'Arial, Helvetica, sans-serif',
      'labelFontSize' => $element['#label_font_size'] ?? '1rem',
      'labelColor' => $element['#label_color'] ?? '#333',
      'inputFontFamily' => $element['#input_font_family'] ?? 'Arial, Helvetica, sans-serif',
      'inputFontSize' => $element['#input_font_size'] ?? '1rem',
      'inputColor' => $element['#input_color'] ?? '#333',
      
      // Feature flags
      'dccEnabled' => $element['#dcc_enabled'] ?? FALSE,
      'threeDSEnabled' => $element['#three_ds_enabled'] ?? FALSE,
      'fraudGuardEnabled' => $element['#fraud_guard_enabled'] ?? FALSE,
      'autoFocus' => $element['#auto_focus'] ?? FALSE,
      'binCheckEnabled' => $element['#bin_check_enabled'] ?? FALSE,
      
      // URLs
      'paymentCallbackUrl' => '/webform/securepay/callback',
    ];

    // Handle DCC mode
    if ($settings['mode'] === 'dcc' && $settings['dccEnabled']) {
      // Initiate payment order for DCC
      $order_data = $securepay_api->initiatePaymentOrder(
        $settings['amount'], 
        'DYNAMIC_CURRENCY_CONVERSION',
        'WF_DCC_' . time()
      );
      
      if ($order_data) {
        $settings['orderToken'] = $order_data['orderToken'];
        $settings['orderId'] = $order_data['orderId'];
      }
    }

    // Handle 3DS2
    if ($settings['threeDSEnabled']) {
      // Initiate payment order for 3DS2
      $order_data = $securepay_api->initiatePaymentOrder(
        $settings['amount'], 
        'THREED_SECURE',
        'WF_3DS2_' . time()
      );
      
      if ($order_data && isset($order_data['threedSecureDetails'])) {
        $settings['threeDSOrderToken'] = $order_data['orderToken'];
        $settings['threeDSClientId'] = $order_data['threedSecureDetails']['providerClientId'];
        $settings['threeDSSessionId'] = $order_data['threedSecureDetails']['sessionId'];
        $settings['threeDSSimpleToken'] = $order_data['threedSecureDetails']['simpleToken'];
        $settings['threeDSSdkUrl'] = $securepay_api->getThreeDS2SdkUrl();
      }
    }

    // Create SecurePay UI container
    $element['securepay_container'] = [
      '#type' => 'markup',
      '#markup' => '<div id="' . $container_id . '" class="securepay-ui-container"></div>',
    ];

    // Payment button
    $element['payment_button'] = [
      '#type' => 'submit',
      '#value' => t('Process Payment'),
      '#name' => 'securepay_' . $element['#name'],
      '#attributes' => [
        'class' => ['webform-securepay-button'],
        'data-container' => $container_id,
      ],
      '#submit' => ['::securePaySubmit'],
      '#ajax' => [
        'callback' => '::securePayAjaxCallback',
        'wrapper' => 'securepay-wrapper-' . $element['#name'],
        'effect' => 'fade',
      ],
    ];

    // Reset button (optional)
    if ($element['#show_reset_button'] ?? TRUE) {
      $element['reset_button'] = [
        '#type' => 'button',
        '#value' => t('Reset'),
        '#attributes' => [
          'class' => ['webform-securepay-reset'],
          'data-container' => $container_id,
        ],
      ];
    }

    // Results container
    $element['result'] = [
      '#type' => 'markup',
      '#markup' => '<div class="webform-securepay-result" style="display: none;"></div>',
    ];

    // DCC options container (for DCC mode)
    if ($settings['mode'] === 'dcc') {
      $element['dcc_options'] = [
        '#type' => 'markup',
        '#markup' => '<div class="dcc-options" style="display: none;"></div>',
      ];
    }

    // Custom callbacks JavaScript
    if (!empty($element['#custom_callbacks'])) {
      $element['#attached']['html_head'][] = [
        [
          '#tag' => 'script',
          '#value' => $element['#custom_callbacks'],
        ],
        'webform_securepay_custom_callbacks_' . $element['#name'],
      ];
    }

    // Add wrapper
    $element['#prefix'] = '<div id="securepay-wrapper-' . $element['#name'] . '" class="webform-securepay-element loading">';
    $element['#suffix'] = '</div>';

    // Attach SecurePay UI Script
    $ui_sdk_url = $securepay_api->getUiSdkUrl();
    $element['#attached']['html_head'][] = [
      [
        '#tag' => 'script',
        '#attributes' => [
          'id' => 'securepay-ui-js',
          'src' => $ui_sdk_url,
          'type' => 'text/javascript',
        ],
      ],
      'webform_securepay_ui_sdk',
    ];

    // Attach settings to JavaScript
    $element['#attached']['drupalSettings']['webformSecurePay'] = $settings;

    return $element;
  }

  /**
   * Validates a SecurePay element.
   */
  public static function validateWebformSecurePay(&$element, FormStateInterface $form_state, &$complete_form) {
    $value = $element['#value'];
    
    // Basic validation
    if (!empty($value) && !is_array($value)) {
      $form_state->setError($element, t('SecurePay element must be an array.'));
      return;
    }

    // Validate required settings
    $config = \Drupal::config('webform_securepay.settings');
    $client_id = $element['#client_id'] ?? $config->get('client_id');
    $merchant_code = $element['#merchant_code'] ?? $config->get('merchant_code');

    if (empty($client_id)) {
      $form_state->setError($element, t('SecurePay Client ID is required.'));
    }

    if (empty($merchant_code)) {
      $form_state->setError($element, t('SecurePay Merchant Code is required.'));
    }

    // Validate amount
    $amount = $element['#amount'];
    if (!empty($amount) && !is_numeric($amount) && !preg_match('/\[.*\]/', $amount)) {
      $form_state->setError($element, t('Amount must be a number in cents or a token.'));
    }

    // Validate card types
    $card_types = array_filter($element['#allowed_card_types'] ?? []);
    if (empty($card_types)) {
      $form_state->setError($element, t('At least one card type must be selected.'));
    }

    // Validate DCC settings
    if (($element['#mode'] ?? 'checkout') === 'dcc' && !($element['#dcc_enabled'] ?? FALSE)) {
      $form_state->setError($element, t('DCC must be enabled when using DCC mode.'));
    }

    // If we have payment data, validate the transaction
    if (!empty($value['token']) && !empty($value['transaction_id'])) {
      // Transaction was processed - validate the result
      if (empty($value['status']) || $value['status'] !== 'paid') {
        $error_message = $value['error'] ?? t('Payment was not successful.');
        $form_state->setError($element, $error_message);
      }
    }
  }

  /**
   * Submit handler for SecurePay payment.
   */
  public static function securePaySubmit(array &$form, FormStateInterface $form_state) {
    // This is handled by the AJAX callback and JavaScript
    // The actual payment processing happens asynchronously
  }

  /**
   * AJAX callback for SecurePay payment.
   */
  public static function securePayAjaxCallback(array &$form, FormStateInterface $form_state) {
    // Return the updated element
    $triggering_element = $form_state->getTriggeringElement();
    $element_name = str_replace('securepay_', '', $triggering_element['#name']);
    
    // Find the parent element
    $element_parents = array_slice($triggering_element['#array_parents'], 0, -1);
    $element = $form;
    foreach ($element_parents as $parent) {
      $element = $element[$parent];
    }
    
    return $element;
  }

}