<?php

namespace Drupal\webform_securepay\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure WebformSecurePay settings for this site.
 */
class WebformSecurePaySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_securepay_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['webform_securepay.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('webform_securepay.settings');

    $form['global_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Global SecurePay Settings'),
      '#description' => $this->t('These settings will be used as defaults for all SecurePay webform elements. Individual forms can override these settings.'),
    ];

    $form['global_settings']['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Merchant ID'),
      '#description' => $this->t('Your SecurePay Merchant ID.'),
      '#default_value' => $config->get('merchant_id'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['global_settings']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Default Password'),
      '#description' => $this->t('Your SecurePay Password. Leave empty to keep current password.'),
      '#maxlength' => 255,
    ];

    if ($config->get('password')) {
      $form['global_settings']['password']['#description'] .= ' ' . $this->t('Current password is set.');
    }

    $form['global_settings']['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#description' => $this->t('SecurePay API endpoint URL.'),
      '#default_value' => $config->get('api_url') ?: 'https://api.securepay.com.au/xmlapi/payment',
      '#required' => TRUE,
    ];

    $form['global_settings']['test_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Default Test Mode'),
      '#description' => $this->t('Enable test mode by default for new SecurePay elements.'),
      '#default_value' => $config->get('test_mode') ?? TRUE,
    ];

    $form['global_settings']['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Currency'),
      '#description' => $this->t('Default currency for payments.'),
      '#options' => [
        'AUD' => 'Australian Dollar (AUD)',
        'USD' => 'US Dollar (USD)',
        'EUR' => 'Euro (EUR)',
        'GBP' => 'British Pound (GBP)',
      ],
      '#default_value' => $config->get('currency') ?: 'AUD',
      '#required' => TRUE,
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout'),
      '#description' => $this->t('Timeout for SecurePay API requests in seconds.'),
      '#default_value' => $config->get('timeout') ?: 30,
      '#min' => 5,
      '#max' => 300,
      '#step' => 1,
    ];

    $form['advanced']['log_transactions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log Transactions'),
      '#description' => $this->t('Log all SecurePay transactions for debugging purposes.'),
      '#default_value' => $config->get('log_transactions') ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate merchant ID format if needed
    $merchant_id = $form_state->getValue('merchant_id');
    if (!empty($merchant_id) && !preg_match('/^[A-Z0-9]+$/', $merchant_id)) {
      $form_state->setErrorByName('merchant_id', $this->t('Merchant ID should contain only uppercase letters and numbers.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('webform_securepay.settings');
    
    $config->set('merchant_id', $form_state->getValue('merchant_id'));
    $config->set('api_url', $form_state->getValue('api_url'));
    $config->set('test_mode', $form_state->getValue('test_mode'));
    $config->set('currency', $form_state->getValue('currency'));
    $config->set('timeout', $form_state->getValue('timeout'));
    $config->set('log_transactions', $form_state->getValue('log_transactions'));
    
    // Only update password if a new one was provided
    $password = $form_state->getValue('password');
    if (!empty($password)) {
      $config->set('password', $password);
    }
    
    $config->save();
    
    parent::submitForm($form, $form_state);
  }

}