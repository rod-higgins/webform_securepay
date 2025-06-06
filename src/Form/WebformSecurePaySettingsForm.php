<?php

namespace Drupal\webform_securepay\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform_securepay\Service\ConfigurationService;
use Drupal\webform_securepay\Service\FormHelperService;
use Drupal\webform_securepay\Service\SecurePayApiServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Simplified SecurePay settings form using helper services.
 */
class WebformSecurePaySettingsForm extends ConfigFormBase {

  public function __construct(
    private readonly ConfigurationService $configService,
    private readonly FormHelperService $formHelper,
    private readonly SecurePayApiServiceInterface $apiService,
  ) {
    parent::__construct(\Drupal::configFactory());
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_securepay.configuration'),
      $container->get('webform_securepay.form_helper'),
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
    $form['#tree'] = false;

    $form['authentication'] = $this->formHelper->buildAuthenticationSection();
    $form['payment'] = $this->formHelper->buildPaymentSection();
    $form['features'] = $this->formHelper->buildFeaturesSection();
    $form['advanced'] = $this->formHelper->buildAdvancedSection();
    $form['test'] = $this->formHelper->buildTestSection();

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $errors = $this->formHelper->validateFormValues($form_state->getValues());
    
    foreach ($errors as $field => $message) {
      $form_state->setErrorByName($field, $message);
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $this->formHelper->processSubmissionValues($form_state->getValues());
    
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
    
    parent::submitForm($form, $form_state);
  }

  public function testConnection(array &$form, FormStateInterface $form_state): void {
    // Handled by AJAX callback
  }

  public function testConnectionAjax(array &$form, FormStateInterface $form_state): array {
    try {
      $success = $this->apiService->testConnection();
      $message = $success 
        ? $this->t('✅ Connection successful!')
        : $this->t('❌ Connection failed.');
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
}