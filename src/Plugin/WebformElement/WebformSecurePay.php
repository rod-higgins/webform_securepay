<?php

namespace Drupal\webform_securepay\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\SecurePayApiService;

/**
 * Provides a 'securepay' element for webforms.
 *
 * @WebformElement(
 *   id = "securepay",
 *   label = @Translation("SecurePay"),
 *   description = @Translation("SecurePay payment processing"),
 *   category = @Translation("Payment"),
 * )
 */
class WebformSecurePay extends WebformElementBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigurationService $config,
    private readonly SecurePayApiService $apiService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.api')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineDefaultProperties() {
    return [
      'amount' => '',
      'currency' => '',
      'mode' => 'checkout',
    ] + parent::defineDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    $properties = parent::getDefaultProperties();
    $properties['currency'] = $this->config->get('currency') ?: 'AUD';
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    if (!$this->config->isConfigured()) {
      $form['configuration_warning'] = [
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('SecurePay is not configured. <a href="@url">Configure settings</a>.', [
            '@url' => \Drupal::url('webform_securepay.admin_settings'),
          ]) . '</div>',
        '#weight' => -100,
      ];
    }

    $form['payment'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
    ];

    $form['payment']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount (cents)'),
      '#description' => $this->t('Payment amount in cents or token like [webform_submission:values:amount].'),
      '#default_value' => $this->getElementProperty($form_state, 'amount'),
      '#required' => TRUE,
    ];

    $form['payment']['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Currency'),
      '#options' => ConfigurationService::getCurrencyOptions(),
      '#default_value' => $this->getElementProperty($form_state, 'currency'),
      '#required' => TRUE,
    ];

    if ($this->config->get('dcc_enabled')) {
      $form['payment']['mode'] = [
        '#type' => 'select',
        '#title' => $this->t('Payment Mode'),
        '#options' => [
          'checkout' => $this->t('Standard Checkout'),
          'dcc' => $this->t('Dynamic Currency Conversion'),
        ],
        '#default_value' => $this->getElementProperty($form_state, 'mode'),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $amount = $form_state->getValue('amount');
    if (!empty($amount) && is_numeric($amount) && $amount <= 0) {
      $form_state->setErrorByName('amount', $this->t('Amount must be greater than zero.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $element = parent::buildForm($element, $webform_submission, $options);

    if (!$this->config->isConfigured()) {
      $element['#markup'] = $this->t('SecurePay is not configured.');
      return $element;
    }

    // Generate unique element ID
    $element_id = 'securepay-' . $element['#webform_key'];
    
    // Process amount - can be numeric or token
    $amount = $element['#amount'] ?? '';
    if (is_numeric($amount)) {
      $processed_amount = (int) $amount;
    } else {
      // Token replacement would happen here in real implementation
      $processed_amount = 1000; // Default for preview
    }

    $element['#theme'] = 'webform_securepay_element';
    $element['#element_id'] = $element_id;
    $element['#amount'] = $processed_amount;
    $element['#currency'] = $element['#currency'] ?? $this->config->get('currency') ?? 'AUD';
    $element['#mode'] = $element['#mode'] ?? 'checkout';

    // Attach required libraries and settings
    $element['#attached']['library'][] = 'webform_securepay/webform_securepay';
    
    // Add external SecurePay SDK
    $element['#attached']['html_head'][] = [
      [
        '#tag' => 'script',
        '#attributes' => [
          'src' => $this->apiService->getUiSdkUrl(),
          'defer' => TRUE,
        ],
      ],
      'webform_securepay_sdk'
    ];

    // Pass settings to JavaScript
    $element['#attached']['drupalSettings']['webformSecurePay'] = [
      'clientId' => $this->config->get('client_id'),
      'merchantCode' => $this->config->get('merchant_code'),
      'environment' => $this->config->get('environment'),
      'currency' => $element['#currency'],
      'amount' => $processed_amount,
      'mode' => $element['#mode'],
      'elementId' => $element_id,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function preview() {
    return [
      '#type' => 'item',
      '#title' => $this->getPluginLabel(),
      '#markup' => $this->t('SecurePay payment form (preview mode)'),
      '#description' => $this->t('Amount: @currency @amount', [
        '@currency' => 'AUD',
        '@amount' => '10.00',
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function formatHtmlItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    
    if (empty($value['transaction_id'])) {
      return $this->t('Payment not completed');
    }

    return $this->t('Transaction ID: @id<br>Status: @status', [
      '@id' => $value['transaction_id'],
      '@status' => $value['status'] ?? 'Unknown',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function formatTextItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    
    if (empty($value['transaction_id'])) {
      return 'Payment not completed';
    }

    return sprintf('Transaction ID: %s, Status: %s', 
      $value['transaction_id'], 
      $value['status'] ?? 'Unknown'
    );
  }

  /**
   * Get element property helper.
   */
  private function getElementProperty(FormStateInterface $form_state, string $property): mixed {
    $element = $form_state->get('element');
    return $element['#' . $property] ?? $this->getDefaultProperty($property);
  }
}