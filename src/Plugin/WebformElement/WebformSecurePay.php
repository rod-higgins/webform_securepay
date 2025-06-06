<?php

namespace Drupal\webform_securepay\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\SecurePayApiService;
use Drupal\Core\Url;

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

  // Element constants
  private const DEFAULT_AMOUNT = 1000; // $10.00 in cents
  private const MIN_AMOUNT = 1;
  private const MAX_AMOUNT = 999999999;
  private const ELEMENT_PREFIX = 'securepay-';
  
  // Payment modes
  private const MODE_CHECKOUT = 'checkout';
  private const MODE_DCC = 'dcc';

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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
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
  protected function defineDefaultProperties(): array {
    return [
      'amount' => '',
      'currency' => '',
      'mode' => self::MODE_CHECKOUT,
      'required' => TRUE,
    ] + parent::defineDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties(): array {
    $properties = parent::getDefaultProperties();
    $properties['currency'] = $this->config->get('currency') ?: ConfigurationService::CURRENCY_AUD;
    $properties['amount'] = self::DEFAULT_AMOUNT;
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    // Show configuration warning if not configured
    if (!$this->config->isConfigured()) {
      $form['configuration_warning'] = [
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('SecurePay is not configured. <a href="@url">Configure settings</a>.', [
            '@url' => Url::fromRoute('webform_securepay.admin_settings')->toString(),
          ]) . '</div>',
        '#weight' => -100,
      ];
    }

    $form['payment'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Settings'),
      '#weight' => 10,
    ];

    $form['payment']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount (cents)'),
      '#description' => $this->t('Payment amount in cents (e.g., 1000 = $10.00) or token like [webform_submission:values:amount].'),
      '#default_value' => $this->getElementProperty($form_state, 'amount'),
      '#required' => TRUE,
      '#size' => 20,
      '#maxlength' => 50,
    ];

    $form['payment']['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Currency'),
      '#options' => ConfigurationService::getCurrencyOptions(),
      '#default_value' => $this->getElementProperty($form_state, 'currency'),
      '#required' => TRUE,
      '#description' => $this->t('Payment currency.'),
    ];

    // Show DCC option only if enabled
    if ($this->config->get('dcc_enabled')) {
      $form['payment']['mode'] = [
        '#type' => 'select',
        '#title' => $this->t('Payment Mode'),
        '#options' => [
          self::MODE_CHECKOUT => $this->t('Standard Checkout'),
          self::MODE_DCC => $this->t('Dynamic Currency Conversion'),
        ],
        '#default_value' => $this->getElementProperty($form_state, 'mode'),
        '#description' => $this->t('DCC allows customers to pay in their card currency.'),
      ];
    }

    // Add validation information
    $form['payment']['validation_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Validation Rules'),
      '#open' => FALSE,
      '#description' => $this->t('Amount must be between @min and @max cents.', [
        '@min' => number_format(self::MIN_AMOUNT),
        '@max' => number_format(self::MAX_AMOUNT),
      ]),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $amount = $form_state->getValue('amount');
    if (!empty($amount)) {
      // Handle both numeric values and tokens
      if (is_numeric($amount)) {
        $amountInt = (int) $amount;
        if ($amountInt < self::MIN_AMOUNT) {
          $form_state->setErrorByName('amount', $this->t('Amount must be at least @min cents.', [
            '@min' => number_format(self::MIN_AMOUNT),
          ]));
        }
        if ($amountInt > self::MAX_AMOUNT) {
          $form_state->setErrorByName('amount', $this->t('Amount cannot exceed @max cents.', [
            '@max' => number_format(self::MAX_AMOUNT),
          ]));
        }
      }
      // If it's not numeric, assume it's a token - will be validated at runtime
    }

    // Validate currency
    $currency = $form_state->getValue('currency');
    if (!empty($currency) && !$this->config->isValidCurrency($currency)) {
      $form_state->setErrorByName('currency', $this->t('Invalid currency selected.'));
    }

    // Validate mode if DCC is available
    $mode = $form_state->getValue('mode');
    if (!empty($mode) && !in_array($mode, [self::MODE_CHECKOUT, self::MODE_DCC], true)) {
      $form_state->setErrorByName('mode', $this->t('Invalid payment mode selected.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL): void {
    parent::prepare($element, $webform_submission);

    // Set default currency if not set
    if (empty($element['#currency'])) {
      $element['#currency'] = $this->config->get('currency') ?: ConfigurationService::CURRENCY_AUD;
    }

    // Set default mode if not set
    if (empty($element['#mode'])) {
      $element['#mode'] = self::MODE_CHECKOUT;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $element, WebformSubmissionInterface $webform_submission, array $options = []): array {
    $element = parent::buildForm($element, $webform_submission, $options);

    if (!$this->config->isConfigured()) {
      $element['#markup'] = '<div class="messages messages--error">' . 
        $this->t('SecurePay is not configured. Please contact the site administrator.') . 
        '</div>';
      return $element;
    }

    // Generate unique element ID
    $elementId = self::ELEMENT_PREFIX . $element['#webform_key'];
    
    // Process amount - can be numeric or token
    $amount = $element['#amount'] ?? self::DEFAULT_AMOUNT;
    if (is_numeric($amount)) {
      $processedAmount = max(self::MIN_AMOUNT, min((int) $amount, self::MAX_AMOUNT));
    } else {
      // TODO: Implement token replacement in real application
      $processedAmount = self::DEFAULT_AMOUNT; // Default for preview/testing
    }

    // Set element properties
    $element['#theme'] = 'webform_securepay_element';
    $element['#element_id'] = $elementId;
    $element['#amount'] = $processedAmount;
    $element['#currency'] = $element['#currency'] ?? ConfigurationService::CURRENCY_AUD;
    $element['#mode'] = $element['#mode'] ?? self::MODE_CHECKOUT;

    // Add CSS/JS libraries
    $element['#attached']['library'][] = 'webform_securepay/webform_securepay';
    
    // Add external SecurePay SDK with error handling
    try {
      $sdkUrl = $this->apiService->getUiSdkUrl();
      $element['#attached']['html_head'][] = [
        [
          '#tag' => 'script',
          '#attributes' => [
            'src' => $sdkUrl,
            'defer' => TRUE,
            'crossorigin' => 'anonymous',
          ],
        ],
        'webform_securepay_sdk'
      ];
    } catch (\Exception $e) {
      $this->getLogger('webform_securepay')->error('Failed to get SDK URL: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    // Pass settings to JavaScript
    $element['#attached']['drupalSettings']['webformSecurePay'] = [
      'clientId' => $this->config->get('client_id'),
      'merchantCode' => $this->config->get('merchant_code'),
      'environment' => $this->config->get('environment'),
      'currency' => $element['#currency'],
      'amount' => $processedAmount,
      'mode' => $element['#mode'],
      'elementId' => $elementId,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function preview(): array {
    $currency = $this->config->get('currency') ?: ConfigurationService::CURRENCY_AUD;
    $amount = self::DEFAULT_AMOUNT / 100;

    return [
      '#type' => 'item',
      '#title' => $this->getPluginLabel(),
      '#markup' => $this->t('SecurePay payment form (preview mode)'),
      '#description' => $this->t('Amount: @currency @amount', [
        '@currency' => $currency,
        '@amount' => number_format($amount, 2),
      ]),
      '#wrapper_attributes' => [
        'class' => ['webform-element-preview'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function formatHtmlItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []): array {
    $value = $this->getValue($element, $webform_submission, $options);
    
    if (empty($value) || !is_array($value) || empty($value['transaction_id'])) {
      return [
        '#markup' => $this->t('Payment not completed'),
      ];
    }

    return [
      '#markup' => $this->t('<strong>Transaction ID:</strong> @id<br><strong>Status:</strong> @status<br><strong>Amount:</strong> @currency @amount', [
        '@id' => htmlspecialchars($value['transaction_id']),
        '@status' => htmlspecialchars($value['status'] ?? 'Unknown'),
        '@currency' => htmlspecialchars($value['currency'] ?? 'AUD'),
        '@amount' => number_format(($value['amount'] ?? 0) / 100, 2),
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function formatTextItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []): string {
    $value = $this->getValue($element, $webform_submission, $options);
    
    if (empty($value) || !is_array($value) || empty($value['transaction_id'])) {
      return 'Payment not completed';
    }

    return sprintf(
      'Transaction ID: %s, Status: %s, Amount: %s %s',
      $value['transaction_id'],
      $value['status'] ?? 'Unknown',
      $value['currency'] ?? 'AUD',
      number_format(($value['amount'] ?? 0) / 100, 2)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isInput(array $element): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isContainer(array $element): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasValue(array $element, WebformSubmissionInterface $webform_submission, array $options = []): bool {
    $value = $this->getValue($element, $webform_submission, $options);
    return !empty($value) && is_array($value) && !empty($value['transaction_id']);
  }

  /**
   * Get element property helper.
   */
  private function getElementProperty(FormStateInterface $form_state, string $property): mixed {
    $element = $form_state->get('element');
    return $element['#' . $property] ?? $this->getDefaultProperty($property);
  }
}