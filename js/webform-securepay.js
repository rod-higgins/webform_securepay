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
      this.container = this.$element.find('.securepay-container')[0];
      this.button = this.$element.find('.securepay-pay-button')[0];
      this.securePayInstance = null;
      
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
        const config = {
          containerId: this.container.id,
          clientId: this.settings.clientId,
          merchantCode: this.settings.merchantCode,
          mode: this.settings.mode || 'checkout',
          
          card: {
            onTokeniseSuccess: (data) => this.handleTokenSuccess(data),
            onTokeniseError: (errors) => this.handleTokenError(errors)
          },
          
          onLoadComplete: () => this.handleLoadComplete()
        };

        this.securePayInstance = new securePayUI.init(config);
      } catch (error) {
        console.error('SecurePay initialization failed:', error);
        this.showError('Payment system unavailable');
      }
    }

    bindEvents() {
      $(this.button).on('click', (e) => {
        e.preventDefault();
        this.processPayment();
      });
    }

    processPayment() {
      this.setButtonState('processing');
      this.showLoading();
      
      try {
        this.securePayInstance.tokenise();
      } catch (error) {
        console.error('Payment failed:', error);
        this.showError('Payment processing failed');
        this.setButtonState('ready');
      }
    }

    handleTokenSuccess(tokenData) {
      const paymentData = {
        token: tokenData.token,
        amount: this.settings.amount,
        currency: this.settings.currency,
        ipAddress: this.getClientIP()
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
      const message = errors[0]?.message || 'Payment validation failed';
      this.showError(message);
      this.setButtonState('ready');
    }

    handlePaymentSuccess(response) {
      this.hideLoading();
      if (response.success) {
        this.showSuccess(response);
      } else {
        this.showError(response.error || 'Payment failed');
        this.setButtonState('ready');
      }
    }

    handlePaymentError(error) {
      this.hideLoading();
      this.showError(error);
      this.setButtonState('ready');
    }

    handleLoadComplete() {
      this.hideLoading();
      this.setButtonState('ready');
    }

    setButtonState(state) {
      const button = $(this.button);
      
      switch (state) {
        case 'processing':
          button.prop('disabled', true).text('Processing...');
          break;
        case 'ready':
        default:
          button.prop('disabled', false).text('Pay Now');
          break;
      }
    }

    showLoading() {
      this.$element.find('.loading-indicator').show();
    }

    hideLoading() {
      this.$element.find('.loading-indicator').hide();
    }

    showSuccess(response) {
      const html = `
        <strong>Payment Successful!</strong><br>
        Transaction ID: ${response.transaction_id}
      `;
      this.showResult(html, 'success');
    }

    showError(message) {
      this.showResult(`<strong>Error:</strong> ${message}`, 'error');
    }

    showResult(html, type) {
      this.$element.find('.payment-result')
        .removeClass('success error')
        .addClass(type)
        .html(html)
        .show();
      this.hideLoading();
    }

    getClientIP() {
      // Simple IP detection (not foolproof)
      return 'unknown';
    }
  }

})(jQuery, Drupal, drupalSettings);