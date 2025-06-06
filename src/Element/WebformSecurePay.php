<?php

namespace Drupal\webform_securepay\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform_securepay\Service\ConfigurationService;

/**
 * Simplified webform element for SecurePay integration.
 *
 * @FormElement("webform_securepay")
 */
class WebformSecurePay extends FormElement {

  use StringTranslationTrait;

  // Validation constants
  private const MIN_AMOUNT = 1;
  private const MAX_AMOUNT = 99999999;

  public function getInfo(): array {
    $class = get_class($this);
    return [
      '#input' => true,
      '#process' => [[$class, 'processWebformSecurePay']],
      '#element_validate' => [[$class, 'validateWebformSecurePay']],
      '#theme_wrappers' => ['container'],
      '#attached' => ['library' => ['webform_securepay/webform_securepay']],
    ];
  }

  public static function processWebformSecurePay(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $element['#tree'] = true;
    
    $elementBuilder = \Drupal::service('webform_securepay.element_builder');
    $configService = \Drupal::service('webform_securepay.configuration');
    
    $containerId = 'securepay-ui-container-' . $element['#name'];
    $settings = $elementBuilder->buildElementSettings($element);
    
    // Handle special modes (DCC, 3DS2)
    $elementBuilder->handleSpecialModes($settings);

    // Build form elements
    $formElements = $elementBuilder->buildFormElements($containerId, $element, $settings);
    $element = array_merge($element, $formElements);

    // Set wrapper
    $element['#prefix'] = '<div id="securepay-wrapper-' . $element['#name'] . '" class="webform-securepay-element loading">';
    $element['#suffix'] = '</div>';

    // Attach scripts and settings
    $element['#attached'] = $elementBuilder->buildAttachments($settings);

    return $element;
  }

  public static function validateWebformSecurePay(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $value = $element['#value'];
    
    if (!empty($value) && !is_array($value)) {
      $form_state->setError($element, t('SecurePay element must be an array.'));
      return;
    }

    self::validateConfiguration($element, $form_state);
    self::validateAmount($element, $form_state);
    self::validateCardTypes($element, $form_state);
    self::validateTransaction($element, $form_state, $value);
  }

  private static function validateConfiguration(array $element, FormStateInterface $form_state): void {
    $configService = \Drupal::service('webform_securepay.configuration');
    
    $requiredSettings = [
      ConfigurationService::CLIENT_ID => 'Client ID',
      ConfigurationService::MERCHANT_CODE => 'Merchant Code',
    ];
    
    foreach ($requiredSettings as $setting => $label) {
      $value = $element['#' . $setting] ?? $configService->get($setting);
      if (empty($value)) {
        $form_state->setError($element, t('SecurePay @setting is required.', ['@setting' => $label]));
      }
    }
  }

  private static function validateAmount(array $element, FormStateInterface $form_state): void {
    $amount = $element['#amount'] ?? '';
    
    if (empty($amount)) {
      $form_state->setError($element, t('Amount is required.'));
      return;
    }

    // Allow tokens or numeric values
    if (!is_numeric($amount) && !preg_match('/\[.*\]/', $amount)) {
      $form_state->setError($element, t('Amount must be a number in cents or a token.'));
      return;
    }

    // Validate numeric amounts
    if (is_numeric($amount)) {
      $amountInt = (int) $amount;
      if ($amountInt < self::MIN_AMOUNT || $amountInt > self::MAX_AMOUNT) {
        $form_state->setError($element, t('Amount must be between @min and @max cents.', [
          '@min' => number_format(self::MIN_AMOUNT),
          '@max' => number_format(self::MAX_AMOUNT),
        ]));
      }
    }
  }

  private static function validateCardTypes(array $element, FormStateInterface $form_state): void {
    $cardTypes = array_filter($element['#allowed_card_types'] ?? []);
    
    if (empty($cardTypes)) {
      $form_state->setError($element, t('At least one card type must be selected.'));
    }

    $validTypes = array_keys(ConfigurationService::getCardTypeOptions());
    $invalidTypes = array_diff($cardTypes, $validTypes);
    
    if (!empty($invalidTypes)) {
      $form_state->setError($element, t('Invalid card types: @types', [
        '@types' => implode(', ', $invalidTypes),
      ]));
    }
  }

  private static function validateTransaction(array $element, FormStateInterface $form_state, ?array $value): void {
    if (empty($value)) {
      return;
    }

    // Validate completed transaction
    if (!empty($value['token']) && !empty($value['transaction_id'])) {
      if (empty($value['status']) || $value['status'] !== 'paid') {
        $errorMessage = $value['error'] ?? t('Payment was not successful.');
        $form_state->setError($element, $errorMessage);
      }
    }

    // Validate DCC settings
    $mode = $element['#mode'] ?? 'checkout';
    $dccEnabled = $element['#dcc_enabled'] ?? false;
    
    if ($mode === 'dcc' && !$dccEnabled) {
      $form_state->setError($element, t('DCC must be enabled when using DCC mode.'));
    }
  }
}