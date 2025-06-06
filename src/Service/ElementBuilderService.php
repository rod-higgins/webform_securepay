<?php

namespace Drupal\webform_securepay\Service;

use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;

/**
 * Service to build SecurePay form elements and reduce plugin complexity.
 */
class ElementBuilderService {

  public function __construct(
    private readonly ConfigurationService $configService,
    private readonly SecurePayApiServiceInterface $apiService,
  ) {}

  /**
   * Build element settings for JavaScript.
   */
  public function buildElementSettings(array $element): array {
    $settingKeys = [
      'client_id', 'merchant_code', 'environment', 'amount', 'currency', 'mode',
      'allowed_card_types', 'show_card_icons', 'background_color',
      'label_font_family', 'label_font_size', 'label_color',
      'input_font_family', 'input_font_size', 'input_color',
      'dcc_enabled', 'three_ds_enabled', 'fraud_guard_enabled',
      'auto_focus', 'bin_check_enabled',
    ];

    $settings = ['paymentCallbackUrl' => '/webform/securepay/callback'];
    
    foreach ($settingKeys as $key) {
      $settings[$key] = $element['#' . $key] ?? $this->configService->get($key);
    }

    // Ensure allowed_card_types is an array of values
    $settings['allowed_card_types'] = array_values(array_filter($settings['allowed_card_types'] ?? []));

    return $settings;
  }

  /**
   * Build form elements for the payment interface.
   */
  public function buildFormElements(string $containerId, array $element, array $settings): array {
    $elements = [
      'securepay_container' => [
        '#type' => 'markup',
        '#markup' => '<div id="' . $containerId . '" class="securepay-ui-container"></div>',
      ],
      'payment_button' => [
        '#type' => 'submit',
        '#value' => t('Process Payment'),
        '#name' => 'securepay_' . $element['#name'],
        '#attributes' => [
          'class' => ['webform-securepay-button'],
          'data-container' => $containerId,
        ],
      ],
      'result' => [
        '#type' => 'markup',
        '#markup' => '<div class="webform-securepay-result" style="display: none;"></div>',
      ],
    ];

    // Add reset button if configured
    if ($element['#show_reset_button'] ?? true) {
      $elements['reset_button'] = [
        '#type' => 'button',
        '#value' => t('Reset'),
        '#attributes' => [
          'class' => ['webform-securepay-reset'],
          'data-container' => $containerId,
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

    return $elements;
  }

  /**
   * Handle special payment modes (DCC, 3DS2).
   */
  public function handleSpecialModes(array &$settings): void {
    // Handle DCC mode
    if ($settings['mode'] === 'dcc' && $settings['dccEnabled']) {
      $orderData = $this->apiService->initiatePaymentOrder(
        $settings['amount'], 
        'DYNAMIC_CURRENCY_CONVERSION',
        'WF_DCC_' . time()
      );
      
      if ($orderData) {
        $settings['orderToken'] = $orderData['orderToken'];
        $settings['orderId'] = $orderData['orderId'];
      }
    }

    // Handle 3DS2 mode
    if ($settings['threeDSEnabled']) {
      $orderData = $this->apiService->initiatePaymentOrder(
        $settings['amount'], 
        'THREED_SECURE',
        'WF_3DS2_' . time()
      );
      
      if ($orderData && isset($orderData['threedSecureDetails'])) {
        $settings = array_merge($settings, [
          'threeDSOrderToken' => $orderData['orderToken'],
          'threeDSClientId' => $orderData['threedSecureDetails']['providerClientId'],
          'threeDSSessionId' => $orderData['threedSecureDetails']['sessionId'],
          'threeDSSimpleToken' => $orderData['threedSecureDetails']['simpleToken'],
          'threeDSSdkUrl' => $this->apiService->getThreeDS2SdkUrl(),
        ]);
      }
    }
  }

  /**
   * Build attachments for the element.
   */
  public function buildAttachments(array $settings): array {
    return [
      'html_head' => [
        [
          [
            '#tag' => 'script',
            '#attributes' => [
              'id' => 'securepay-ui-js',
              'src' => $this->apiService->getUiSdkUrl(),
              'type' => 'text/javascript',
            ],
          ],
          'webform_securepay_ui_sdk',
        ]
      ],
      'library' => ['webform_securepay/webform_securepay'],
      'drupalSettings' => [
        'webformSecurePay' => $settings,
      ],
    ];
  }
}