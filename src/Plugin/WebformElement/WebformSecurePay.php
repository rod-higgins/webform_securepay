<?php

namespace Drupal\webform_securepay\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform_securepay\Service\ConfigurationService;

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
      $container->get('webform_securepay.configuration')
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
  public function preview() {
    return [
      '#type' => 'item',
      '#title' => $this->getPluginLabel(),
      '#markup' => $this->t('SecurePay payment form (preview)'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function formatHtmlItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    
    if (empty($value['transaction_id'])) {
      return '';
    }

    return $this->t('Transaction ID: @id<br>Status: @status', [
      '@id' => $value['transaction_id'],
      '@status' => $value['status'] ?? 'Unknown',
    ]);
  }

  /**
   * Get element property helper.
   */
  private function getElementProperty(FormStateInterface $form_state, string $property): mixed {
    $element = $form_state->get('element');
    return $element['#' . $property] ?? $this->getDefaultProperty($property);
  }
}