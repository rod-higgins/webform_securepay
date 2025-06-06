<?php

namespace Drupal\webform_securepay\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\Core\Render\Element\Textfield;

/**
 * Provides a webform element for SecurePay integration.
 *
 * @FormElement("webform_securepay")
 */
class WebformSecurePay extends FormElement {

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
    ];
  }

  /**
   * Processes a SecurePay element.
   */
  public static function processWebformSecurePay(&$element, FormStateInterface $form_state, &$complete_form) {
    $element['#tree'] = TRUE;

    $element['payment_button'] = [
      '#type' => 'submit',
      '#value' => t('Process Payment'),
      '#name' => 'securepay_' . $element['#name'],
      '#attributes' => [
        'class' => ['webform-securepay-button'],
      ],
      '#submit' => ['::securePaySubmit'],
      '#ajax' => [
        'callback' => '::securePayAjaxCallback',
        'wrapper' => 'securepay-wrapper-' . $element['#name'],
        'effect' => 'fade',
      ],
    ];

    $element['#prefix'] = '<div id="securepay-wrapper-' . $element['#name'] . '">';
    $element['#suffix'] = '</div>';

    return $element;
  }

  /**
   * Validates a SecurePay element.
   */
  public static function validateWebformSecurePay(&$element, FormStateInterface $form_state, &$complete_form) {
    // Add custom validation logic here
    $value = $element['#value'];
    if (!empty($value) && !is_array($value)) {
      $form_state->setError($element, t('SecurePay element must be an array.'));
    }
  }

}