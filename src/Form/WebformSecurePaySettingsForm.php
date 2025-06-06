<?php

namespace Drupal\webform_securepay\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure WebformSecurePay settings for this site.
 */
class WebformSecurePaySettingsForm extends ConfigFormBase {

  private const CURRENCY_OPTIONS = [
    'AUD' => 'Australian Dollar (AUD)',
    'USD' => 'US Dollar (USD)',
    'EUR' => 'Euro (EUR)',
    'GBP' => 'British Pound (GBP)',
    'NZD' => 'New Zealand Dollar (NZD)',
    'CAD' => 'Canadian Dollar (CAD)',
    'JPY' => 'Japanese Yen (JPY)',
    'SGD' => 'Singapore Dollar (SGD)',
  ];

  private const CARD_TYPE_OPTIONS = [
    'visa' => 'Visa',
    'mastercard' => 'Mastercard',
    'amex' => 'American Express',
    'diners' => 'Diners Club',
  ];

  public function __construct(
    private readonly ConfigurationService $configService,
    private readonly SecurePayApiServiceInterface $securePayApi,
  ) {
    parent::__construct(\Drupal::configFactory());
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.api')
    );
  }

  public function getFormId(): string {
    return 'webform_securepay_admin_settings';
  }

  protected function getEditableConfigNames(): array {
    return [ConfigurationService::CONFIG_NAME];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['introduction'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure SecurePay payment processing for Drupal Webforms using the modern REST API with OAuth 2.0.') . '</p>',
    ];

    $form['help_links'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Documentation: @api_docs | @support', [
        '@api_docs' => Link::fromTextAndUrl('API Docs', Url::fromUri('https://auspost.com.au/payments/docs/securepay/'))->toString(),
        '@support' => Link::fromTextAndUrl('Support', Url::fromUri('https://www.securepay.com.au/support/'))->toString(),
      ]) . '</p>',
    ];

    $form += $this->buildFormSections();

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate card types
    $card_types = array_filter($form_state->getValue(ConfigurationService::ALLOWED_CARD_TYPES) ?: []);
    if (empty($card_types)) {
      $form_state->setErrorByName(ConfigurationService::ALLOWED_CARD_TYPES, 
        $this->t('At least one card type must be selected.'));
    }

    // Validate email if notifications enabled
    if ($form_state->getValue(ConfigurationService::EMAIL_NOTIFICATIONS) && 
        empty($form_state->getValue(ConfigurationService::NOTIFICATION_EMAIL))) {
      $form_state->setErrorByName(ConfigurationService::NOTIFICATION_EMAIL, 
        $this->t('Notification email is required when email notifications are enabled.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(ConfigurationService::CONFIG_NAME);
    
    $config_keys = [
      ConfigurationService::CLIENT_ID,
      ConfigurationService::MERCHANT_CODE,
      ConfigurationService::ENVIRONMENT,
      ConfigurationService::CURRENCY,
      ConfigurationService::DEFAULT_MODE,
      ConfigurationService::ORDER_ID_PREFIX,
      ConfigurationService::SHOW_CARD_ICONS,
      ConfigurationService::BACKGROUND_COLOR,
      ConfigurationService::DCC_ENABLED,
      ConfigurationService::THREE_DS_ENABLED,
      ConfigurationService::FRAUD_GUARD_ENABLED,
      ConfigurationService::TIMEOUT,
      ConfigurationService::LOG_TRANSACTIONS,
      ConfigurationService::DEBUG_MODE,
      ConfigurationService::EMAIL_NOTIFICATIONS,
      ConfigurationService::NOTIFICATION_EMAIL,
    ];

    foreach ($config_keys as $key) {
      $config->set($key, $form_state->getValue($key));
    }

    // Handle card types specially
    $card_types = array_values(array_filter($form_state->getValue(ConfigurationService::ALLOWED_CARD_TYPES)));
    $config->set(ConfigurationService::ALLOWED_CARD_TYPES, $card_types);

    // Only update client secret if provided
    $client_secret = $form_state->getValue(ConfigurationService::CLIENT_SECRET);
    if (!empty($client_secret)) {
      $config->set(ConfigurationService::CLIENT_SECRET, $client_secret);
    }
    
    $config->save();
    
    parent::submitForm($form, $form_state);
  }

  public function testConnectionAjax(array &$form, FormStateInterface $form_state): array {
    try {
      $success = $this->securePayApi->testConnection();
      $message = $success 
        ? $this->t('✅ Connection successful!')
        : $this->t('❌ Connection failed. Check credentials.');
      $class = $success ? 'messages--status' : 'messages--error';
    }
    catch (\Exception $e) {
      $message = $this->t('❌ Connection failed: @error', ['@error' => $e->getMessage()]);
      $class = 'messages--error';
    }

    return [
      '#type' => 'markup',
      '#markup' => '<div class="messages ' . $class . '">' . $message . '</div>',
      '#prefix' => '<div id="test-connection-result">',
      '#suffix' => '</div>',
    ];
  }

  private function buildFormSections(): array {
    return [
      'authentication' => $this->buildAuthenticationSection(),
      'payment' => $this->buildPaymentSection(),
      'ui' => $this->buildUiSection(),
      'features' => $this->buildFeaturesSection(),
      'advanced' => $this->buildAdvancedSection(),
      'test' => $this->buildTestSection(),
    ];
  }

  private function buildAuthenticationSection(): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Authentication Settings'),
      '#description' => $this->t('OAuth 2.0 credentials from your SecurePay merchant dashboard.'),
      
      ConfigurationService::CLIENT_ID => [
        '#type' => 'textfield',
        '#title' => $this->t('Client ID'),
        '#default_value' => $this->configService->get(ConfigurationService::CLIENT_ID),
        '#required' => TRUE,
        '#maxlength' => 255,
      ],
      
      ConfigurationService::CLIENT_SECRET => [
        '#type' => 'password',
        '#title' => $this->t('Client Secret'),
        '#description' => $this->configService->get(ConfigurationService::CLIENT_SECRET) 
          ? $this->t('Current secret is set. Leave empty to keep current.')
          : $this->t('Enter your client secret.'),
        '#maxlength' => 255,
      ],
      
      ConfigurationService::MERCHANT_CODE => [
        '#type' => 'textfield',
        '#title' => $this->t('Merchant Code'),
        '#default_value' => $this->configService->get(ConfigurationService::MERCHANT_CODE),
        '#required' => TRUE,
        '#maxlength' => 255,
      ],
      
      ConfigurationService::ENVIRONMENT => [
        '#type' => 'select',
        '#title' => $this->t('Environment'),
        '#options' => [
          'sandbox' => $this->t('Sandbox (Testing)'),
          'live' => $this->t('Live (Production)'),
        ],
        '#default_value' => $this->configService->get(ConfigurationService::ENVIRONMENT),
        '#required' => TRUE,
      ],
    ];
  }

  private function buildPaymentSection(): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Default Payment Settings'),
      
      ConfigurationService::CURRENCY => [
        '#type' => 'select',
        '#title' => $this->t('Default Currency'),
        '#options' => self::CURRENCY_OPTIONS,
        '#default_value' => $this->configService->get(ConfigurationService::CURRENCY),
        '#required' => TRUE,
      ],
      
      ConfigurationService::DEFAULT_MODE => [
        '#type' => 'select',
        '#title' => $this->t('Default Payment Mode'),
        '#options' => [
          'checkout' => $this->t('Checkout (Standard)'),
          'dcc' => $this->t('DCC (Dynamic Currency Conversion)'),
        ],
        '#default_value' => $this->configService->get(ConfigurationService::DEFAULT_MODE),
      ],
      
      ConfigurationService::ORDER_ID_PREFIX => [
        '#type' => 'textfield',
        '#title' => $this->t('Order ID Prefix'),
        '#default_value' => $this->configService->get(ConfigurationService::ORDER_ID_PREFIX),
        '#maxlength' => 10,
      ],
    ];
  }

  private function buildUiSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('UI Settings'),
      '#open' => FALSE,
      
      ConfigurationService::ALLOWED_CARD_TYPES => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Default Allowed Card Types'),
        '#options' => self::CARD_TYPE_OPTIONS,
        '#default_value' => $this->configService->get(ConfigurationService::ALLOWED_CARD_TYPES),
      ],
      
      ConfigurationService::SHOW_CARD_ICONS => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show Card Icons by Default'),
        '#default_value' => $this->configService->get(ConfigurationService::SHOW_CARD_ICONS),
      ],
      
      ConfigurationService::BACKGROUND_COLOR => [
        '#type' => 'textfield',
        '#title' => $this->t('Background Color'),
        '#default_value' => $this->configService->get(ConfigurationService::BACKGROUND_COLOR),
      ],
    ];
  }

  private function buildFeaturesSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Feature Settings'),
      '#open' => FALSE,
      
      ConfigurationService::DCC_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Dynamic Currency Conversion by Default'),
        '#default_value' => $this->configService->get(ConfigurationService::DCC_ENABLED),
      ],
      
      ConfigurationService::THREE_DS_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable 3D Secure 2 by Default'),
        '#default_value' => $this->configService->get(ConfigurationService::THREE_DS_ENABLED),
      ],
      
      ConfigurationService::FRAUD_GUARD_ENABLED => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable FraudGuard by Default'),
        '#default_value' => $this->configService->get(ConfigurationService::FRAUD_GUARD_ENABLED),
      ],
    ];
  }

  private function buildAdvancedSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
      
      ConfigurationService::TIMEOUT => [
        '#type' => 'number',
        '#title' => $this->t('API Timeout (seconds)'),
        '#default_value' => $this->configService->get(ConfigurationService::TIMEOUT),
        '#min' => 5,
        '#max' => 300,
      ],
      
      ConfigurationService::LOG_TRANSACTIONS => [
        '#type' => 'checkbox',
        '#title' => $this->t('Log Transactions'),
        '#default_value' => $this->configService->get(ConfigurationService::LOG_TRANSACTIONS),
      ],
      
      ConfigurationService::DEBUG_MODE => [
        '#type' => 'checkbox',
        '#title' => $this->t('Debug Mode'),
        '#description' => $this->t('<strong>Do not enable in production!</strong>'),
        '#default_value' => $this->configService->get(ConfigurationService::DEBUG_MODE),
      ],
      
      ConfigurationService::EMAIL_NOTIFICATIONS => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Email Notifications'),
        '#default_value' => $this->configService->get(ConfigurationService::EMAIL_NOTIFICATIONS),
      ],
      
      ConfigurationService::NOTIFICATION_EMAIL => [
        '#type' => 'email',
        '#title' => $this->t('Notification Email'),
        '#default_value' => $this->configService->get(ConfigurationService::NOTIFICATION_EMAIL),
        '#states' => [
          'visible' => [
            ':input[name="' . ConfigurationService::EMAIL_NOTIFICATIONS . '"]' => ['checked' => TRUE],
          ],
        ],
      ],
    ];
  }

  private function buildTestSection(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => FALSE,
      
      'test_connection' => [
        '#type' => 'submit',
        '#value' => $this->t('Test SecurePay Connection'),
        '#submit' => ['::testConnection'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::testConnectionAjax',
          'wrapper' => 'test-connection-result',
        ],
      ],
      
      'test_result' => [
        '#type' => 'markup',
        '#prefix' => '<div id="test-connection-result">',
        '#suffix' => '</div>',
      ],
    ];
  }

  public function testConnection(array &$form, FormStateInterface $form_state): void {
    // Handled by AJAX callback
  }
}