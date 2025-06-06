<?php

namespace Drupal\webform_securepay\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\FormBuilderService;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for SecurePay settings.
 */
class WebformSecurePaySettingsForm extends ConfigFormBase {

  public function __construct(
    private readonly ConfigurationService $configService,
    private readonly FormBuilderService $formBuilder,
    private readonly SecurePayApiServiceInterface $apiService,
  ) {
    parent::__construct(\Drupal::configFactory());
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.form_builder'),
      $container->get('webform_securepay.api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'webform_securepay_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ConfigurationService::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = false;

    // Add configuration status
    $form['status'] = $this->buildStatusSection();

    // Build form sections using FormBuilderService
    $form['authentication'] = $this->formBuilder->buildAuthenticationSection();
    $form['payment'] = $this->formBuilder->buildPaymentSection();
    $form['features'] = $this->formBuilder->buildFeaturesSection();
    $form['advanced'] = $this->formBuilder->buildAdvancedSection();
    $form['test'] = $this->formBuilder->buildTestSection();

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $errors = $this->formBuilder->validateFormValues($form_state->getValues());
    
    foreach ($errors as $field => $message) {
      $form_state->setErrorByName($field, $message);
    }

    // Additional validation for live environment
    $environment = $form_state->getValue(ConfigurationService::ENVIRONMENT);
    if ($environment === 'live') {
      $this->validateLiveEnvironment($form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $this->formBuilder->processSubmissionValues($form_state->getValues());
    
    $config = $this->config(ConfigurationService::CONFIG_NAME);
    
    // Save all configuration values
    $configKeys = [
      ConfigurationService::CLIENT_ID,
      ConfigurationService::MERCHANT_CODE,
      ConfigurationService::ENVIRONMENT,
      ConfigurationService::CURRENCY,
      ConfigurationService::ORDER_ID_PREFIX,
      ConfigurationService::ALLOWED_CARD_TYPES,
      ConfigurationService::DCC_ENABLED,
      ConfigurationService::THREE_DS_ENABLED,
      ConfigurationService::FRAUD_GUARD_ENABLED,
      ConfigurationService::TIMEOUT,
      ConfigurationService::LOG_TRANSACTIONS,
      ConfigurationService::DEBUG_MODE,
      ConfigurationService::RATE_LIMIT_ENABLED,
      ConfigurationService::MAX_ATTEMPTS_PER_HOUR,
    ];

    foreach ($configKeys as $key) {
      if (array_key_exists($key, $values)) {
        $config->set($key, $values[$key]);
      }
    }

    // Only update client secret if provided
    $clientSecret = $values[ConfigurationService::CLIENT_SECRET] ?? '';
    if (!empty($clientSecret)) {
      $config->set(ConfigurationService::CLIENT_SECRET, $clientSecret);
    }
    
    $config->save();
    
    // Clear any cached tokens since config changed
    \Drupal::cache()->delete('webform_securepay_token');
    
    $this->messenger()->addStatus($this->t('SecurePay configuration has been saved.'));
    
    parent::submitForm($form, $form_state);
  }

  /**
   * Test connection submit handler.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    // Handled by AJAX callback
  }

  /**
   * AJAX callback for connection test.
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state): array {
    try {
      $success = $this->apiService->testConnection();
      
      if ($success) {
        $message = $this->t('✅ Connection successful! SecurePay API is responding.');
        $class = 'messages--status';
      } else {
        $message = $this->t('❌ Connection failed. Please check your credentials.');
        $class = 'messages--error';
      }
    }
    catch (\Exception $e) {
      $message = $this->t('❌ Connection error: @error', ['@error' => $e->getMessage()]);
      $class = 'messages--error';
    }

    return [
      '#type' => 'markup',
      '#markup' => '<div class="messages ' . $class . '">' . $message . '</div>',
      '#prefix' => '<div id="test-connection-result">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * Build configuration status section.
   */
  private function buildStatusSection(): array {
    $isConfigured = $this->configService->isConfigured();
    $environment = $this->configService->get(ConfigurationService::ENVIRONMENT);
    
    $status = [
      '#type' => 'fieldset',
      '#title' => $this->t('Configuration Status'),
      '#weight' => -10,
    ];

    if ($isConfigured) {
      $status['status'] = [
        '#markup' => '<div class="messages messages--status">' . 
          $this->t('✅ SecurePay is configured for @env environment.', ['@env' => $environment]) . 
          '</div>',
      ];
    } else {
      $status['status'] = [
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('⚠️ SecurePay is not fully configured. Please complete the required fields below.') . 
          '</div>',
      ];
    }

    return $status;
  }

  /**
   * Validate live environment requirements.
   */
  private function validateLiveEnvironment(FormStateInterface $form_state): void {
    // Check SSL requirement
    if (empty($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != 443) {
      $form_state->setErrorByName(ConfigurationService::ENVIRONMENT, 
        $this->t('SSL/HTTPS is required when using live environment.'));
    }

    // Warn about debug mode
    if ($form_state->getValue(ConfigurationService::DEBUG_MODE)) {
      $this->messenger()->addWarning(
        $this->t('Debug mode should not be enabled in live environment.')
      );
    }
  }
}