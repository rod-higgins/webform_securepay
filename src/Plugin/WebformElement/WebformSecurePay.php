<?php

namespace Drupal\webform_securepay\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'securepay' element.
 *
 * @WebformElement(
 *   id = "securepay",
 *   label = @Translation("SecurePay"),
 *   description = @Translation("Provides SecurePay payment processing."),
 *   category = @Translation("Payment"),
 * )
 */
class WebformSecurePay extends WebformElementBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a WebformSecurePay object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineDefaultProperties() {
    return [
      // SecurePay specific properties
      'merchant_id' => '',
      'password' => '',
      'api_url' => 'https://api.securepay.com.au/xmlapi/payment',
      'test_mode' => TRUE,
      'amount' => '',
      'currency' => 'AUD',
      'order_id_prefix' => 'WF_',
      // Standard properties
      'title' => '',
      'description' => '',
      'required' => FALSE,
    ] + parent::defineDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    $config = $this->configFactory->get('webform_securepay.settings');
    $properties = parent::getDefaultProperties();
    
    // Override with global settings if element properties are empty
    if (empty($properties['merchant_id'])) {
      $properties['merchant_id'] = $config->get('merchant_id') ?? '';
    }
    if (empty($properties['password'])) {
      $properties['password'] = $config->get('password') ?? '';
    }
    if (empty($properties['api_url'])) {
      $properties['api_url'] = $config->get('api_url') ?? 'https://api.securepay.com.au/xmlapi/payment';
    }
    $properties['test_mode'] = $config->get('test_mode') ?? TRUE;
    $properties['currency'] = $config->get('currency') ?? 'AUD';
    
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $config = $this->configFactory->get('webform_securepay.settings');

    $form['securepay'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('SecurePay settings'),
      '#description' => $this->t('Configure SecurePay payment settings. Leave fields empty to use global defaults.'),
    ];

    $form['securepay']['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#description' => $this->t('SecurePay Merchant ID. Global default: @default', [
        '@default' => $config->get('merchant_id') ?: $this->t('Not set'),
      ]),
      '#default_value' => $this->getElementProperty($form_state, 'merchant_id'),
      '#maxlength' => 255,
    ];

    $form['securepay']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('SecurePay Password. Global default: @default', [
        '@default' => $config->get('password') ? $this->t('Set') : $this->t('Not set'),
      ]),
      '#default_value' => $this->getElementProperty($form_state, 'password'),
      '#maxlength' => 255,
    ];

    $form['securepay']['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#description' => $this->t('SecurePay API endpoint URL.'),
      '#default_value' => $this->getElementProperty($form_state, 'api_url'),
      '#required' => TRUE,
    ];

    $form['securepay']['test_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Test mode'),
      '#description' => $this->t('Enable test mode for SecurePay transactions.'),
      '#default_value' => $this->getElementProperty($form_state, 'test_mode'),
    ];

    $form['securepay']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#description' => $this->t('Payment amount in cents (e.g., 1000 for $10.00). Can use tokens like [webform_submission:values:amount_field].'),
      '#default_value' => $this->getElementProperty($form_state, 'amount'),
      '#required' => TRUE,
    ];

    $form['securepay']['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Currency'),
      '#description' => $this->t('Payment currency.'),
      '#options' => [
        'AUD' => 'Australian Dollar (AUD)',
        'USD' => 'US Dollar (USD)',
        'EUR' => 'Euro (EUR)',
        'GBP' => 'British Pound (GBP)',
      ],
      '#default_value' => $this->getElementProperty($form_state, 'currency'),
      '#required' => TRUE,
    ];

    $form['securepay']['order_id_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order ID Prefix'),
      '#description' => $this->t('Prefix for order IDs to ensure uniqueness.'),
      '#default_value' => $this->getElementProperty($form_state, 'order_id_prefix'),
      '#maxlength' => 10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function formatHtmlItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    if (empty($value)) {
      return '';
    }

    $format = $this->getItemFormat($element);
    switch ($format) {
      case 'value':
        return $value['transaction_id'] ?? '';
      
      case 'raw':
        return '<pre>' . htmlspecialchars(print_r($value, TRUE)) . '</pre>';
        
      default:
        $items = [];
        if (isset($value['transaction_id'])) {
          $items[] = '<strong>' . $this->t('Transaction ID') . ':</strong> ' . $value['transaction_id'];
        }
        if (isset($value['amount'])) {
          $items[] = '<strong>' . $this->t('Amount') . ':</strong> ' . $value['amount'];
        }
        if (isset($value['status'])) {
          $items[] = '<strong>' . $this->t('Status') . ':</strong> ' . $value['status'];
        }
        return implode('<br>', $items);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function formatTextItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    if (empty($value)) {
      return '';
    }

    $items = [];
    if (isset($value['transaction_id'])) {
      $items[] = $this->t('Transaction ID') . ': ' . $value['transaction_id'];
    }
    if (isset($value['amount'])) {
      $items[] = $this->t('Amount') . ': ' . $value['amount'];
    }
    if (isset($value['status'])) {
      $items[] = $this->t('Status') . ': ' . $value['status'];
    }
    return implode("\n", $items);
  }

  /**
   * {@inheritdoc}
   */
  public function preview() {
    return [
      '#type' => 'item',
      '#title' => $this->getPluginLabel(),
      '#markup' => $this->t('SecurePay payment element (preview mode)'),
    ];
  }

  /**
   * Get element property with fallback to global config.
   */
  protected function getElementProperty(FormStateInterface $form_state, $property) {
    $element = $form_state->get('element');
    if (!empty($element['#' . $property])) {
      return $element['#' . $property];
    }
    
    $config = $this->configFactory->get('webform_securepay.settings');
    return $config->get($property) ?? $this->getDefaultProperty($property);
  }

}