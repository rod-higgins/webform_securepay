(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * SecurePay Webform Integration
   */
  Drupal.behaviors.webformSecurePay = {
    attach: function (context, settings) {
      $('.webform-securepay-element', context)
        .once('webform-securepay')
        .each(function () {
          const $element = $(this);
          const elementSettings = settings.webformSecurePay || {};
          
          if (elementSettings.elementId && this.id.includes(elementSettings.elementId)) {
            new SecurePayElement(this, elementSettings);
          }
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
      this.container = this.$element.find('.securepay-container')[0];
      this.button = this.$element.find('.securepay-pay-button')[0];
      this.securePayInstance = null;
      this.isProcessing = false;
      
      this.init();
    }

    init() {
      if (!this.container || !this.button) {
        console.error('SecurePay: Required elements not found');
        return;
      }

      this.showLoading();
      this.waitForScript(() => {
        this.initializeUI();
        this.bindEvents();
      });
    }

    waitForScript(callback) {
      let attempts = 0;
      const maxAttempts = 50; // 5 seconds max wait
      
      const checkScript = () => {
        if (typeof window.securePayUI !== 'undefined') {
          callback();
        } else if (attempts < maxAttempts) {
          attempts++;
          setTimeout(checkScript, 100);
        } else {
          console.error('SecurePay: SDK failed to load');
          this.showError('Payment system unavailable. Please refresh the page.');
          this.hideLoading();
        }
      };
      
      checkScript();
    }

    initializeUI() {
      try {
        if (!this.settings.clientId || !this.settings.merchantCode) {
          throw new Error('Missing client configuration');
        }

        const config = {
          containerId: this.container.id,
          clientId: this.settings.clientId,
          merchantCode: this.settings.merchantCode,
          environment: this.settings.environment || 'sandbox',
          mode: this.settings.mode || 'checkout',
          
          card: {
            onTokeniseSuccess: (data) => this.handleTokenSuccess(data),
            onTokeniseError: (errors) => this.handleTokenError(errors),
            onLoadComplete: () => this.handleLoadComplete(),
            onLoadError: (error) => this.handleLoadError(error)
          }
        };

        this.securePayInstance = new window.securePayUI.init(config);
      } catch (error) {
        console.error('SecurePay initialization failed:', error);
        this.showError('Payment system initialization failed');
        this.hideLoading();
      }
    }

    bindEvents() {
      if (!this.button) return;

      $(this.button).on('click', (e) => {
        e.preventDefault();
        if (!this.isProcessing) {
          this.processPayment();
        }
      });
    }

    processPayment() {
      if (!this.securePayInstance) {
        this.showError('Payment system not ready');
        return;
      }

      this.isProcessing = true;
      this.setButtonState('processing');
      this.showLoading();
      this.clearResults();
      
      try {
        this.securePayInstance.tokenise();
      } catch (error) {
        console.error('Payment tokenization failed:', error);
        this.showError('Payment processing failed');
        this.resetProcessing();
      }
    }

    handleTokenSuccess(tokenData) {
      if (!tokenData || !tokenData.token) {
        this.showError('Invalid payment token received');
        this.resetProcessing();
        return;
      }

      const paymentData = {
        token: tokenData.token,
        amount: this.settings.amount || 0,
        currency: this.settings.currency || 'AUD',
        ipAddress: this.getClientIP(),
        orderId: this.generateOrderId()
      };

      this.submitPayment(paymentData);
    }

    submitPayment(paymentData) {
      $.ajax({
        url: '/webform/securepay/callback',
        method: 'POST',
        data: JSON.stringify(paymentData),
        contentType: 'application/json',
        dataType: 'json',
        timeout: 30000,
        success: (response) => this.handlePaymentSuccess(response),
        error: (xhr, status, error) => this.handlePaymentError(xhr, status, error)
      });
    }

    handleTokenError(errors) {
      let message = 'Payment validation failed';
      
      if (Array.isArray(errors) && errors.length > 0) {
        message = errors[0].message || errors[0].description || message;
      } else if (typeof errors === 'string') {
        message = errors;
      }
      
      this.showError(message);
      this.resetProcessing();
    }

    handlePaymentSuccess(response) {
      this.hideLoading();
      
      if (response && response.success) {
        this.showSuccess(response);
        this.setButtonState('completed');
      } else {
        const errorMsg = response?.error || 'Payment failed';
        this.showError(errorMsg);
        this.resetProcessing();
      }
    }

    handlePaymentError(xhr, status, error) {
      this.hideLoading();
      
      let message = 'Payment processing failed';
      
      if (xhr.responseJSON && xhr.responseJSON.error) {
        message = xhr.responseJSON.error;
      } else if (status === 'timeout') {
        message = 'Payment request timed out. Please try again.';
      } else if (status === 'abort') {
        message = 'Payment request was cancelled.';
      } else if (error) {
        message = `Network error: ${error}`;
      }
      
      this.showError(message);
      this.resetProcessing();
    }

    handleLoadComplete() {
      this.hideLoading();
      this.setButtonState('ready');
    }

    handleLoadError(error) {
      console.error('SecurePay load error:', error);
      this.showError('Payment form failed to load');
      this.hideLoading();
    }

    resetProcessing() {
      this.isProcessing = false;
      this.setButtonState('ready');
      this.hideLoading();
    }

    setButtonState(state) {
      const $button = $(this.button);
      
      switch (state) {
        case 'processing':
          $button.prop('disabled', true).text('Processing...');
          break;
        case 'completed':
          $button.prop('disabled', true).text('Payment Complete');
          break;
        case 'ready':
        default:
          $button.prop('disabled', false).text('Pay Now');
          break;
      }
    }

    showLoading() {
      this.$element.find('.loading-indicator').show();
    }

    hideLoading() {
      this.$element.find('.loading-indicator').hide();
    }

    clearResults() {
      this.$element.find('.payment-result').hide().empty();
    }

    showSuccess(response) {
      const html = `
        <strong>Payment Successful!</strong><br>
        Transaction ID: ${this.escapeHtml(response.transaction_id || 'N/A')}
      `;
      this.showResult(html, 'success');
    }

    showError(message) {
      const html = `<strong>Error:</strong> ${this.escapeHtml(message)}`;
      this.showResult(html, 'error');
    }

    showResult(html, type) {
      this.$element.find('.payment-result')
        .removeClass('success error')
        .addClass(type)
        .html(html)
        .show();
      this.hideLoading();
    }

    generateOrderId() {
      const timestamp = Date.now();
      const random = Math.random().toString(36).substr(2, 5);
      return `WF_${timestamp}_${random}`;
    }

    getClientIP() {
      // Basic client info - server should determine real IP
      return 'browser';
    }

    escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
  }

})(jQuery, Drupal, drupalSettings);