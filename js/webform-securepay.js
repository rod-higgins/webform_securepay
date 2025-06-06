(function ($, Drupal, drupalSettings) {
  'use strict';

  // Constants
  const CONSTANTS = {
    SDK_WAIT_TIMEOUT: 5000,
    SDK_CHECK_INTERVAL: 100,
    REQUEST_TIMEOUT: 30000,
    MAX_RETRY_ATTEMPTS: 3,
    RETRY_DELAY: 1000,
    ORDER_ID_PREFIX: 'WF_',
    MIN_AMOUNT: 1,
    MAX_AMOUNT: 999999999
  };

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
            try {
              new SecurePayElement(this, elementSettings);
            } catch (error) {
              console.error('SecurePay: Failed to initialize element:', error);
              $element.find('.payment-result')
                .addClass('error')
                .html('<strong>Error:</strong> Payment system initialization failed')
                .show();
            }
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
      this.settings = this.validateSettings(settings);
      this.$element = $(element);
      this.container = this.$element.find('.securepay-container')[0];
      this.button = this.$element.find('.securepay-pay-button')[0];
      this.securePayInstance = null;
      this.isProcessing = false;
      this.retryCount = 0;
      
      this.init();
    }

    /**
     * Validate settings object
     */
    validateSettings(settings) {
      const required = ['clientId', 'merchantCode', 'environment'];
      for (const field of required) {
        if (!settings[field]) {
          throw new Error(`Missing required setting: ${field}`);
        }
      }

      // Validate amount
      const amount = parseInt(settings.amount, 10);
      if (isNaN(amount) || amount < CONSTANTS.MIN_AMOUNT || amount > CONSTANTS.MAX_AMOUNT) {
        throw new Error('Invalid payment amount');
      }

      // Validate environment
      if (!['sandbox', 'live'].includes(settings.environment)) {
        throw new Error('Invalid environment setting');
      }

      return {
        ...settings,
        amount: amount,
        currency: (settings.currency || 'AUD').toUpperCase(),
        mode: settings.mode || 'checkout'
      };
    }

    /**
     * Initialize the payment element
     */
    init() {
      if (!this.container || !this.button) {
        console.error('SecurePay: Required DOM elements not found');
        this.showError('Payment form initialization failed');
        return;
      }

      this.showLoading();
      this.waitForScript(() => {
        this.initializeUI();
        this.bindEvents();
      });
    }

    /**
     * Wait for SecurePay SDK to load
     */
    waitForScript(callback) {
      let attempts = 0;
      const maxAttempts = Math.floor(CONSTANTS.SDK_WAIT_TIMEOUT / CONSTANTS.SDK_CHECK_INTERVAL);
      
      const checkScript = () => {
        if (typeof window.securePayUI !== 'undefined') {
          callback();
        } else if (attempts < maxAttempts) {
          attempts++;
          setTimeout(checkScript, CONSTANTS.SDK_CHECK_INTERVAL);
        } else {
          console.error('SecurePay: SDK failed to load after timeout');
          this.showError('Payment system unavailable. Please refresh the page and try again.');
          this.hideLoading();
        }
      };
      
      checkScript();
    }

    /**
     * Initialize SecurePay UI
     */
    initializeUI() {
      try {
        const config = {
          containerId: this.container.id,
          clientId: this.settings.clientId,
          merchantCode: this.settings.merchantCode,
          environment: this.settings.environment,
          mode: this.settings.mode,
          
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

    /**
     * Bind event handlers
     */
    bindEvents() {
      if (!this.button) return;

      $(this.button).on('click', (e) => {
        e.preventDefault();
        if (!this.isProcessing && this.securePayInstance) {
          this.processPayment();
        }
      });

      // Prevent double submission
      $(this.element).closest('form').on('submit', (e) => {
        if (this.isProcessing) {
          e.preventDefault();
          return false;
        }
      });
    }

    /**
     * Process payment
     */
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

    /**
     * Handle successful tokenization
     */
    handleTokenSuccess(tokenData) {
      if (!this.validateTokenData(tokenData)) {
        this.showError('Invalid payment token received');
        this.resetProcessing();
        return;
      }

      const paymentData = {
        token: tokenData.token,
        amount: this.settings.amount,
        currency: this.settings.currency,
        orderId: this.generateOrderId()
      };

      this.submitPayment(paymentData);
    }

    /**
     * Validate token data
     */
    validateTokenData(tokenData) {
      return tokenData && 
             typeof tokenData === 'object' && 
             tokenData.token && 
             typeof tokenData.token === 'string' &&
             tokenData.token.length > 0;
    }

    /**
     * Submit payment to server
     */
    submitPayment(paymentData) {
      $.ajax({
        url: '/webform/securepay/callback',
        method: 'POST',
        data: JSON.stringify(paymentData),
        contentType: 'application/json',
        dataType: 'json',
        timeout: CONSTANTS.REQUEST_TIMEOUT,
        success: (response) => this.handlePaymentSuccess(response),
        error: (xhr, status, error) => this.handlePaymentError(xhr, status, error)
      });
    }

    /**
     * Handle tokenization errors
     */
    handleTokenError(errors) {
      let message = 'Payment validation failed';
      
      if (Array.isArray(errors) && errors.length > 0) {
        const error = errors[0];
        message = error.message || error.description || message;
      } else if (typeof errors === 'string') {
        message = errors;
      } else if (errors && typeof errors === 'object' && errors.message) {
        message = errors.message;
      }
      
      this.showError(this.sanitizeErrorMessage(message));
      this.resetProcessing();
    }

    /**
     * Handle successful payment response
     */
    handlePaymentSuccess(response) {
      this.hideLoading();
      
      if (this.validatePaymentResponse(response) && response.success) {
        this.showSuccess(response);
        this.setButtonState('completed');
        this.disableForm();
      } else {
        const errorMsg = this.sanitizeErrorMessage(response?.error || 'Payment failed');
        this.showError(errorMsg);
        this.resetProcessing();
      }
    }

    /**
     * Validate payment response
     */
    validatePaymentResponse(response) {
      return response && 
             typeof response === 'object' &&
             typeof response.success === 'boolean';
    }

    /**
     * Handle payment errors
     */
    handlePaymentError(xhr, status, error) {
      this.hideLoading();
      
      let message = 'Payment processing failed';
      
      try {
        if (xhr.responseJSON && xhr.responseJSON.error) {
          message = xhr.responseJSON.error;
        } else if (status === 'timeout') {
          message = 'Payment request timed out. Please try again.';
        } else if (status === 'abort') {
          message = 'Payment request was cancelled.';
        } else if (xhr.status === 0) {
          message = 'Network connection error. Please check your internet connection.';
        } else if (xhr.status === 429) {
          message = 'Too many requests. Please wait a moment and try again.';
        } else if (xhr.status >= 500) {
          message = 'Server error. Please try again later.';
        } else if (error) {
          message = `Network error: ${error}`;
        }
      } catch (e) {
        // Use default message if parsing fails
      }
      
      this.showError(this.sanitizeErrorMessage(message));
      
      // Implement retry logic for network errors
      if (this.isRetryableError(xhr.status) && this.retryCount < CONSTANTS.MAX_RETRY_ATTEMPTS) {
        this.retryCount++;
        setTimeout(() => {
          this.processPayment();
        }, CONSTANTS.RETRY_DELAY * this.retryCount);
      } else {
        this.resetProcessing();
      }
    }

    /**
     * Check if error is retryable
     */
    isRetryableError(statusCode) {
      return [0, 429, 502, 503, 504].includes(statusCode);
    }

    /**
     * Handle UI load completion
     */
    handleLoadComplete() {
      this.hideLoading();
      this.setButtonState('ready');
    }

    /**
     * Handle UI load errors
     */
    handleLoadError(error) {
      console.error('SecurePay load error:', error);
      this.showError('Payment form failed to load');
      this.hideLoading();
    }

    /**
     * Reset processing state
     */
    resetProcessing() {
      this.isProcessing = false;
      this.retryCount = 0;
      this.setButtonState('ready');
      this.hideLoading();
    }

    /**
     * Set button state
     */
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

    /**
     * Show loading indicator
     */
    showLoading() {
      this.$element.find('.loading-indicator').show();
    }

    /**
     * Hide loading indicator
     */
    hideLoading() {
      this.$element.find('.loading-indicator').hide();
    }

    /**
     * Clear result messages
     */
    clearResults() {
      this.$element.find('.payment-result').hide().empty();
    }

    /**
     * Show success message
     */
    showSuccess(response) {
      const transactionId = this.sanitizeTransactionId(response.transaction_id || 'N/A');
      const html = `
        <strong>Payment Successful!</strong><br>
        Transaction ID: ${transactionId}
      `;
      this.showResult(html, 'success');
    }

    /**
     * Show error message
     */
    showError(message) {
      const safeMessage = this.sanitizeErrorMessage(message);
      const html = `<strong>Error:</strong> ${safeMessage}`;
      this.showResult(html, 'error');
    }

    /**
     * Show result message
     */
    showResult(html, type) {
      this.$element.find('.payment-result')
        .removeClass('success error')
        .addClass(type)
        .html(html)
        .show();
      this.hideLoading();
    }

    /**
     * Disable form after successful payment
     */
    disableForm() {
      this.$element.find('input, button, select').prop('disabled', true);
    }

    /**
     * Generate unique order ID
     */
    generateOrderId() {
      const timestamp = Date.now();
      const random = Math.random().toString(36).substr(2, 5);
      return `${CONSTANTS.ORDER_ID_PREFIX}${timestamp}_${random}`;
    }

    /**
     * Sanitize transaction ID for display
     */
    sanitizeTransactionId(transactionId) {
      const sanitized = String(transactionId).replace(/[^a-zA-Z0-9_-]/g, '');
      return sanitized.length > 0 ? sanitized : 'N/A';
    }

    /**
     * Sanitize error message for display
     */
    sanitizeErrorMessage(message) {
      if (!message || typeof message !== 'string') {
        return 'An error occurred';
      }
      
      // Remove HTML tags and limit length
      const cleaned = message.replace(/<[^>]*>/g, '').trim();
      return cleaned.length > 200 ? cleaned.substr(0, 200) + '...' : cleaned;
    }

    /**
     * Escape HTML for safe display
     */
    escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
  }

  // Global error handler for unhandled SecurePay errors
  window.addEventListener('unhandledrejection', function(event) {
    if (event.reason && event.reason.message && event.reason.message.includes('SecurePay')) {
      console.error('Unhandled SecurePay error:', event.reason);
      event.preventDefault(); // Prevent the error from appearing in console
    }
  });

})(jQuery, Drupal, drupalSettings);