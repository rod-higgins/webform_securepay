<?php

namespace Drupal\webform_securepay\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\ElementBuilderService;
use Drupal\webform_securepay\Service\FormHelperService;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;

/**
 * Provides a simplified 'securepay' element.
 *
 * @WebformElement(
 *   id = "securepay",
 *   label = @Translation("SecurePay"),
 *   description = @Translation("Provides SecurePay payment processing with modern REST API."),
 *   category = @Translation("Payment"),
 * )
 */
class WebformSecurePay extends WebformElementBase implements ContainerFactoryPluginInterface {

  // Element property keys
  private const PROPERTY_AMOUNT = 'amount';
  private const PROPERTY_CURRENCY = 'currency';
  private const PROPERTY_MODE = 'mode';
  private const PROPERTY_CARD_TYPES = 'allowed_card_types';
  private const PROPERTY_DCC_ENABLED = 'dcc_enabled';
  private const PROPERTY_THREE_DS = 'three_ds_enabled';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigurationService $configService,
    private readonly ElementBuilderService $elementBuilder,
    private readonly FormHelperService $formHelper,
    private readonly SecurePayApiServiceInterface $apiService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.element_builder'),
      $container->get('webform_securepay.form_helper'),
      $container->get('webform_securepay.api')
    );
  }

  protected function defineDefaultProperties() {
    return [
      // Payment settings
      self::PROPERTY_AMOUNT => '',
      self::PROPERTY_CURRENCY => '',
      self::PROPERTY_MODE => '',
      
      // Card settings
      self::PROPERTY_CARD_TYPES => [],
      'show_card_icons' => null,
      
      // Feature flags
      self::PROPERTY_DCC_ENABLED => null,
      self::PROPERTY_THREE_DS => null,
      'fraud_guard_enabled' => null,
      
      // Standard properties
      'title' => '',
      'description' => '',
      'required' => false,
    ] + parent::defineDefaultProperties();
  }

  public function getDefaultProperties() {
    $properties = parent::getDefaultProperties();
    
    // Merge with global configuration defaults
    $configMappings = [
      self::PROPERTY_CURRENCY => ConfigurationService::CURRENCY,
      self::PROPERTY_MODE => 'checkout',
      self::PROPERTY_CARD_TYPES => ConfigurationService::ALLOWED_CARD_TYPES,
      self::PROPERTY_DCC_ENABLED => ConfigurationService::DCC_ENABLED,
      self::PROPERTY_THREE_DS => ConfigurationService::THREE_DS_ENABLED,
      'fraud_guard_enabled' => ConfigurationService::FRAUD_GUARD_ENABLED,
    ];

    foreach ($configMappings as $property => $configKey) {
      if (empty($properties[$property])) {
        $properties[$property] = $this->configService->get($configKey);
      }
    }
    
    return $properties;
  }

  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $form['payment'] = $this->buildPaymentSection($form_state);
    $form['card'] = $this->buildCardSection($form_state);
    $form['features'] = $this->buildFeaturesSection($form_state);
    $form['test'] = $this->formHelper->buildTestSection();

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $this->validateRequiredFields($form_state);
    $this->validateBusinessRules($form_state);
  }

  public function preview() {
    return [
      '#type' => 'item',
      '#title' => $this->getPluginLabel(),
      '#markup' => $this->t('SecurePay payment element (preview mode)'),
    ];
  }

  protected function formatHtmlItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    if (empty($value)) {
      return '';
    }

    $format = $this->getItemFormat($element);
    
    return match($format) {
      'value' => $value['transaction_id'] ?? '',
      'raw' => '<pre>' . htmlspecialchars(print_r($value, true)) . '</pre>',
      default => $this->formatTransactionSummary($value),
    };
  }

  private function buildPaymentSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
      self::PROPERTY_AMOUNT => [
        '#type' => 'textfield',
        '#title' => $this->t('Amount'),
        '#description' => $this->t('Payment amount in cents or token like [webform_submission:values:amount_field].'),
        '#default_value' => $this->getElementProperty($form_state, self::PROPERTY_AMOUNT),
        '#required' => true,
      ],
      self::PROPERTY_CURRENCY => [
        '#type' => 'select',
        '#title' => $this->t('Currency'),
        '#options' => ConfigurationService::getCurrencyOptions(),
        '#default_value' => $this->getElementProperty($form_state, self::PROPERTY_CURRENCY),
        '#required' => true,
      ],
      self::PROPERTY_MODE => [
        '#type' => 'select',
        '#title' => $this->t('Payment Mode'),
        '#options' => [
          'checkout' => $this->t('Checkout (Standard)'),
          'dcc' => $this->t('DCC (Dynamic Currency Conversion)'),
        ],
        '#default_value' => $this->getElementProperty($form_state, self::PROPERTY_MODE),
      ],
    ];
  }

  private function buildCardSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Card Settings'),
      self::PROPERTY_CARD_TYPES => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed Card Types'),
        '#options' => ConfigurationService::getCardTypeOptions(),
        '#default_value' => $this->getElementProperty($form_state, self::PROPERTY_CARD_TYPES),
        '#required' => true,
      ],
      'show_card_icons' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show Card Icons'),
        '#default_value' => $this->getElementProperty($form_state, 'show_card_icons'),
      ],
    ];
  }

  private function buildFeaturesSection(FormStateInterface $form_state): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Feature Settings'),
      '#open' => false,
      self::PROPERTY_DCC_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Dynamic Currency Conversion'),
        '#default_value' => $this->getElementProperty($form_state, self::PROPERTY_DCC_ENABLED),
      ],
      self::PROPERTY_THREE_DS => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable 3D Secure 2'),
        '#default_value' => $this->getElementProperty($form_state, self::PROPERTY_THREE_DS),
      ],
      'fraud_guard_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable FraudGuard'),
        '#default_value' => $this->getElementProperty($form_state, 'fraud_guard_enabled'),
      ],
    ];
  }

  private function validateRequiredFields(FormStateInterface $form_state): void {
    $amount = $form_state->getValue(self::PROPERTY_AMOUNT);
    if (!empty($amount) && !is_numeric($amount) && !preg_match('/\[.*\]/', $amount)) {
      $form_state->setErrorByName(self::PROPERTY_AMOUNT, 
        $this->t('Amount must be a number in cents or a token.'));
    }
  }

  private function validateBusinessRules(FormStateInterface $form_state): void {
    // Validate card types
    $cardTypes = array_filter($form_state->getValue(self::PROPERTY_CARD_TYPES) ?: []);
    if (empty($cardTypes)) {
      $form_state->setErrorByName(self::PROPERTY_CARD_TYPES, 
        $this->t('At least one card type must be selected.'));
    }

    // Validate DCC settings
    $mode = $form_state->getValue(self::PROPERTY_MODE);
    $dccEnabled = $form_state->getValue(self::PROPERTY_DCC_ENABLED);
    if ($mode === 'dcc' && !$dccEnabled) {
      $form_state->setErrorByName(self::PROPERTY_DCC_ENABLED, 
        $this->t('DCC must be enabled when using DCC mode.'));
    }
  }

  private function getElementProperty(FormStateInterface $form_state, string $property): mixed {
    $element = $form_state->get('element');
    if (!empty($element['#' . $property])) {
      return $element['#' . $property];
    }
    
    return $this->configService->get($property) ?? $this->getDefaultProperty($property);
  }

  private function formatTransactionSummary(array $value): string {
    $items = [];
    $fields = [
      'transaction_id' => $this->t('Transaction ID'),
      'amount' => $this->t('Amount'),
      'status' => $this->t('Status'),
      'currency' => $this->t('Currency'),
    ];

    foreach ($fields as $field => $label) {
      if (isset($value[$field])) {
        $items[] = '<strong>' . $label . ':</strong> ' . $value[$field];
      }
    }

    return implode('<br>', $items);
  }
}