(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * SecurePay Webform Integration - Simplified Version
   */
  Drupal.behaviors.webformSecurePay = {
    attach: function (context, settings) {
      $('.webform-securepay-element', context)
        .once('webform-securepay')
        .each(function () {
          new SecurePayElement(this, settings.webformSecurePay || {});
        });
    }
  };

  /**
   * SecurePay Element Handler
   */
  class SecurePayElement {
    constructor(element, settings) {
      this.element = element;
      this.settings = settings;
      this.$element = $(element);
      this.$container = this.$element.find('.securepay-ui-container');
      this.containerId = this.$container.attr('id');
      this.securePayInstance = null;
      
      if (!this.containerId) {
        console.error('SecurePay: Container ID not found');
        return;
      }

      this.init();
    }

    init() {
      this.waitForScript(() => {
        this.initializeUI();
        this.bindEvents();
      });
    }

    waitForScript(callback) {
      if (typeof securePayUI !== 'undefined') {
        callback();
      } else {
        setTimeout(() => this.waitForScript(callback), 100);
      }
    }

    initializeUI() {
      try {
        const config = this.buildConfig();
        this.securePayInstance = new securePayUI.init(config);
        this.hideLoading();
        this.$element.addClass('ready');
      } catch (error) {
        console.error('SecurePay: Failed to initialize', error);
        this.showError('Payment system unavailable');
      }
    }

    buildConfig() {
      return {
        containerId: this.containerId,
        clientId: this.settings.clientId,
        merchantCode: this.settings.merchantCode,
        mode: this.settings.mode || 'checkout',
        
        card: {
          allowedCardTypes: this.settings.allowedCardTypes || ['visa', 'mastercard'],
          onFormValidityChange: (valid) => this.updateButtonState(valid),
          onTokeniseSuccess: (data) => this.handleTokenSuccess(data),
          onTokeniseError: (errors) => this.handleTokenError(errors)
        },
        
        style: this.buildStyleConfig(),
        onLoadComplete: () => this.handleLoadComplete()
      };
    }

    buildStyleConfig() {
      return {
        backgroundColor: this.settings.backgroundColor || 'transparent',
        label: {
          font: {
            family: this.settings.labelFontFamily || 'inherit',
            size: this.settings.labelFontSize || '1rem',
            color: this.settings.labelColor || '#333'
          }
        },
        input: {
          font: {
            family: this.settings.inputFontFamily || 'inherit',
            size: this.settings.inputFontSize || '1rem',
            color: this.settings.inputColor || '#333'
          }
        }
      };
    }

    bindEvents() {
      this.$element.find('.webform-securepay-button')
        .on('click', (e) => {
          e.preventDefault();
          this.processPayment(e.target);
        });

      this.$element.find('.webform-securepay-reset')
        .on('click', (e) => {
          e.preventDefault();
          this.resetForm();
        });
    }

    processPayment(button) {
      this.setButtonState(button, 'processing');
      this.showLoading();
      this.clearResults();
      
      try {
        this.securePayInstance.tokenise();
      } catch (error) {
        console.error('SecurePay: Tokenization failed', error);
        this.showError('Payment processing failed');
        this.setButtonState(button, 'ready');
      }
    }

    resetForm() {
      try {
        this.securePayInstance.reset();
        this.clearResults();
        this.setButtonState(this.$element.find('.webform-securepay-button')[0], 'ready');
      } catch (error) {
        console.error('SecurePay: Reset failed', error);
      }
    }

    handleTokenSuccess(tokenData) {
      const paymentData = {
        token: tokenData.token,
        amount: this.settings.amount,
        merchantCode: tokenData.merchantCode,
        scheme: tokenData.scheme,
        last4: tokenData.last4
      };

      $.ajax({
        url: '/webform/securepay/callback',
        method: 'POST',
        data: JSON.stringify(paymentData),
        contentType: 'application/json',
        success: (response) => this.handlePaymentSuccess(response),
        error: () => this.handlePaymentError('Network error')
      });
    }

    handleTokenError(errors) {
      let message = 'Payment validation failed';
      if (Array.isArray(errors) && errors.length > 0) {
        message = errors[0].detail || errors[0].message || message;
      }
      this.showError(message);
      this.setButtonState(this.$element.find('.webform-securepay-button')[0], 'ready');
    }

    handlePaymentSuccess(response) {
      if (response.success) {
        this.showSuccess(response);
        this.$element.trigger('securepay:success', [response]);
      } else {
        this.showError(response.error || 'Payment failed');
        this.setButtonState(this.$element.find('.webform-securepay-button')[0], 'ready');
      }
    }

    handlePaymentError(error) {
      this.showError(error);
      this.setButtonState(this.$element.find('.webform-securepay-button')[0], 'ready');
      this.$element.trigger('securepay:error', [error]);
    }

    handleLoadComplete() {
      this.hideLoading();
      this.$element.removeClass('loading').addClass('ready');
    }

    updateButtonState(valid) {
      const $button = this.$element.find('.webform-securepay-button');
      $button.prop('disabled', !valid);
    }

    setButtonState(button, state) {
      const $button = $(button);
      const states = {
        ready: { disabled: false, text: Drupal.t('Process Payment'), class: '' },
        processing: { disabled: true, text: Drupal.t('Processing...'), class: 'processing' },
        invalid: { disabled: true, text: Drupal.t('Invalid'), class: 'invalid' }
      };

      const config = states[state] || states.ready;
      $button
        .prop('disabled', config.disabled)
        .val(config.text)
        .removeClass('processing invalid')
        .addClass(config.class);
    }

    showLoading() {
      this.$element.find('.loading-indicator').show();
    }

    hideLoading() {
      this.$element.find('.loading-indicator').hide();
    }

    showSuccess(response) {
      const html = `
        <div class="success-message">
          <strong>${Drupal.t('Payment Successful')}</strong><br>
          ${Drupal.t('Transaction ID')}: ${response.transaction_id}<br>
          ${Drupal.t('Amount')}: ${response.currency} ${(response.amount / 100).toFixed(2)}
        </div>
      `;
      this.showResult(html, 'success');
    }

    showError(message) {
      const html = `<div class="error-message">${message}</div>`;
      this.showResult(html, 'error');
    }

    showResult(html, type) {
      const $result = this.$element.find('.webform-securepay-result');
      $result
        .removeClass('success error')
        .addClass(type)
        .html(html)
        .show();
      this.hideLoading();
    }

    clearResults() {
      this.$element.find('.webform-securepay-result')
        .hide()
        .removeClass('success error')
        .empty();
    }
  }

})(jQuery, Drupal, drupalSettings);