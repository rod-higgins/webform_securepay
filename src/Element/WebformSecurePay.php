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

  public function getInfo(): array {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#process' => [[$class, 'processWebformSecurePay']],
      '#element_validate' => [[$class, 'validateWebformSecurePay']],
      '#theme_wrappers' => ['container'],
      '#attached' => ['library' => ['webform_securepay/webform_securepay']],
    ];
  }

  public static function processWebformSecurePay(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $element['#tree'] = TRUE;
    
    $securepay_api = \Drupal::service('webform_securepay.api');
    $config = \Drupal::service('webform_securepay.configuration');
    
    $container_id = 'securepay-ui-container-' . $element['#name'];
    
    $settings = self::buildElementSettings($element, $config);
    
    // Handle special modes
    if ($settings['mode'] === 'dcc' && $settings['dccEnabled']) {
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

    if ($settings['threeDSEnabled']) {
      $order_data = $securepay_api->initiatePaymentOrder(
        $settings['amount'], 
        'THREED_SECURE',
        'WF_3DS2_' . time()
      );
      
      if ($order_data && isset($order_data['threedSecureDetails'])) {
        $settings = array_merge($settings, [
          'threeDSOrderToken' => $order_data['orderToken'],
          'threeDSClientId' => $order_data['threedSecureDetails']['providerClientId'],
          'threeDSSessionId' => $order_data['threedSecureDetails']['sessionId'],
          'threeDSSimpleToken' => $order_data['threedSecureDetails']['simpleToken'],
          'threeDSSdkUrl' => $securepay_api->getThreeDS2SdkUrl(),
        ]);
      }
    }

    // Build form elements
    $element = array_merge($element, self::buildFormElements($container_id, $element, $settings));

    // Attach scripts and settings
    $element['#attached']['html_head'][] = [
      [
        '#tag' => 'script',
        '#attributes' => [
          'id' => 'securepay-ui-js',
          'src' => $securepay_api->getUiSdkUrl(),
          'type' => 'text/javascript',
        ],
      ],
      'webform_securepay_ui_sdk',
    ];

    $element['#attached']['drupalSettings']['webformSecurePay'] = $settings;

    return $element;
  }

  public static function validateWebformSecurePay(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $value = $element['#value'];
    $config = \Drupal::service('webform_securepay.configuration');
    
    if (!empty($value) && !is_array($value)) {
      $form_state->setError($element, t('SecurePay element must be an array.'));
      return;
    }

    // Validate required settings
    $required_settings = ['client_id', 'merchant_code'];
    foreach ($required_settings as $setting) {
      $setting_value = $element['#' . $setting] ?? $config->get($setting);
      if (empty($setting_value)) {
        $form_state->setError($element, t('SecurePay @setting is required.', ['@setting' => ucwords(str_replace('_', ' ', $setting))]));
      }
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

    // Validate transaction if present
    if (!empty($value['token']) && !empty($value['transaction_id'])) {
      if (empty($value['status']) || $value['status'] !== 'paid') {
        $error_message = $value['error'] ?? t('Payment was not successful.');
        $form_state->setError($element, $error_message);
      }
    }
  }

  private static function buildElementSettings(array $element, $config): array {
    $setting_keys = [
      'client_id', 'merchant_code', 'environment', 'amount', 'currency', 'mode',
      'allowed_card_types', 'show_card_icons', 'background_color',
      'label_font_family', 'label_font_size', 'label_color',
      'input_font_family', 'input_font_size', 'input_color',
      'dcc_enabled', 'three_ds_enabled', 'fraud_guard_enabled',
      'auto_focus', 'bin_check_enabled',
    ];

    $settings = ['paymentCallbackUrl' => '/webform/securepay/callback'];
    
    foreach ($setting_keys as $key) {
      $settings[$key] = $element['#' . $key] ?? $config->get($key);
    }

    // Ensure allowed_card_types is an array of values
    $settings['allowed_card_types'] = array_values(array_filter($settings['allowed_card_types'] ?? []));

    return $settings;
  }

  private static function buildFormElements(string $container_id, array $element, array $settings): array {
    $elements = [
      'securepay_container' => [
        '#type' => 'markup',
        '#markup' => '<div id="' . $container_id . '" class="securepay-ui-container"></div>',
      ],
      'payment_button' => [
        '#type' => 'submit',
        '#value' => t('Process Payment'),
        '#name' => 'securepay_' . $element['#name'],
        '#attributes' => [
          'class' => ['webform-securepay-button'],
          'data-container' => $container_id,
        ],
      ],
      'result' => [
        '#type' => 'markup',
        '#markup' => '<div class="webform-securepay-result" style="display: none;"></div>',
      ],
    ];

    // Add reset button if configured
    if ($element['#show_reset_button'] ?? TRUE) {
      $elements['reset_button'] = [
        '#type' => 'button',
        '#value' => t('Reset'),
        '#attributes' => [
          'class' => ['webform-securepay-reset'],
          'data-container' => $container_id,
        ],
      ];
    }

    // Add DCC options container
    if ($settings['mode'] === 'dcc') {
      $elements['dcc_options'] = [
        '#type' => 'markup',
        '#markup' => '<div class="dcc-options" style="display: none;"></div>',
      ];
    }

    // Add wrapper
    $elements['#prefix'] = '<div id="securepay-wrapper-' . $element['#name'] . '" class="webform-securepay-element loading">';
    $elements['#suffix'] = '</div>';

    return $elements;
  }
}