(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * SecurePay Webform Integration
   */
  Drupal.behaviors.webformSecurePay = {
    attach: function (context, settings) {
      $('.webform-securepay-element', context).once('webform-securepay').each(function () {
        new SecurePayElement($(this), settings.webformSecurePay || {});
      });
    }
  };

  /**
   * SecurePay Element Class
   */
  class SecurePayElement {
    constructor($element, settings) {
      this.$element = $element;
      this.settings = settings;
      this.$container = $element.find('.securepay-ui-container');
      this.containerId = this.$container.attr('id');
      this.securePayInstance = null;
      
      if (!this.containerId) {
        console.error('SecurePay: Container ID not found');
        return;
      }

      this.initialize();
    }

    initialize() {
      this.waitForSecurePayScript(() => {
        this.initializeSecurePayUI();
        this.setupEventHandlers();
      });
    }

    waitForSecurePayScript(callback) {
      if (typeof securePayUI !== 'undefined') {
        callback();
      } else {
        setTimeout(() => this.waitForSecurePayScript(callback), 100);
      }
    }

    initializeSecurePayUI() {
      try {
        const uiConfig = this.buildUIConfig();
        this.securePayInstance = new securePayUI.init(uiConfig);
        this.$element.data('securePayUIInstance', this.securePayInstance);
        this.hideLoadingIndicator();
      } catch (error) {
        console.error('SecurePay: Failed to initialize UI Component', error);
        this.displayError('Payment system unavailable. Please try again later.');
      }
    }

    buildUIConfig() {
      return {
        containerId: this.containerId,
        scriptId: 'securepay-ui-js',
        clientId: this.settings.clientId,
        merchantCode: this.settings.merchantCode,
        mode: this.settings.mode || 'checkout',
        
        card: {
          allowedCardTypes: this.settings.allowedCardTypes || ['visa', 'mastercard', 'amex', 'diners'],
          showCardIcons: this.settings.showCardIcons !== false,
          onCardTypeChange: (cardType) => this.handleCardTypeChange(cardType),
          onBINChange: (cardBIN) => this.handleBINChange(cardBIN),
          onFormValidityChange: (valid) => this.updateSubmitButtonState(valid),
          onDCCQuoteSuccess: (quote) => this.handleDCCQuoteSuccess(quote),
          onDCCQuoteError: (errors) => this.handleDCCQuoteError(errors),
          onTokeniseSuccess: (tokenisedCard) => this.handleTokeniseSuccess(tokenisedCard),
          onTokeniseError: (errors) => this.handleTokeniseError(errors)
        },
        
        style: this.buildStyleConfig(),
        checkoutInfo: this.buildCheckoutInfo(),
        onLoadComplete: () => this.handleLoadComplete()
      };
    }

    buildStyleConfig() {
      return {
        backgroundColor: this.settings.backgroundColor || 'rgba(255, 255, 255, 0.1)',
        label: {
          font: {
            family: this.settings.labelFontFamily || 'Arial, Helvetica, sans-serif',
            size: this.settings.labelFontSize || '1rem',
            color: this.settings.labelColor || '#333'
          }
        },
        input: {
          font: {
            family: this.settings.inputFontFamily || 'Arial, Helvetica, sans-serif',
            size: this.settings.inputFontSize || '1rem',
            color: this.settings.inputColor || '#333'
          }
        }
      };
    }

    buildCheckoutInfo() {
      if (this.settings.mode === 'dcc' && this.settings.orderToken) {
        return { orderToken: this.settings.orderToken };
      }
      return undefined;
    }

    setupEventHandlers() {
      this.setupPaymentButton();
      this.setupResetButton();
      
      if (this.settings.threeDSEnabled) {
        this.setup3DS2();
      }
    }

    setupPaymentButton() {
      const $button = this.$element.find('.webform-securepay-button');
      
      $button.on('click', (e) => {
        e.preventDefault();
        this.processPayment($button);
      });
    }

    setupResetButton() {
      const $resetButton = this.$element.find('.webform-securepay-reset');
      
      if ($resetButton.length) {
        $resetButton.on('click', (e) => {
          e.preventDefault();
          this.resetForm();
        });
      }
    }

    processPayment($button) {
      this.setButtonProcessing($button, true);
      this.showLoadingIndicator();
      this.clearResults();
      
      try {
        this.securePayInstance.tokenise();
        this.$element.trigger('securepay:tokeniseInitiated');
      } catch (error) {
        console.error('SecurePay: Failed to tokenise', error);
        this.displayError('Payment processing failed. Please try again.');
        this.setButtonProcessing($button, false);
      }
    }

    resetForm() {
      try {
        this.securePayInstance.reset();
        this.clearResults();
        this.setButtonProcessing(this.$element.find('.webform-securepay-button'), false);
        this.$element.trigger('securepay:formReset');
      } catch (error) {
        console.error('SecurePay: Failed to reset form', error);
      }
    }

    // Event Handlers
    handleCardTypeChange(cardType) {
      console.log('SecurePay: Card type changed to', cardType);
      this.$element.trigger('securepay:cardTypeChange', [cardType]);
      this.updateCardTypeDisplay(cardType);
    }

    handleBINChange(cardBIN) {
      console.log('SecurePay: Card BIN changed to', cardBIN);
      this.$element.trigger('securepay:binChange', [cardBIN]);
    }

    handleDCCQuoteSuccess(quote) {
      console.log('SecurePay: DCC quote success', quote);
      this.$element.trigger('securepay:dccQuoteSuccess', [quote]);
      this.displayDCCOptions(quote);
    }

    handleDCCQuoteError(errors) {
      console.error('SecurePay: DCC quote error', errors);
      this.$element.trigger('securepay:dccQuoteError', [errors]);
      this.displayError('Currency conversion unavailable. Please try again.');
    }

    handleTokeniseSuccess(tokenisedCard) {
      console.log('SecurePay: Tokenisation successful', tokenisedCard);
      this.$element.trigger('securepay:tokeniseSuccess', [tokenisedCard]);
      this.processTokenisedCard(tokenisedCard);
    }

    handleTokeniseError(errors) {
      console.error('SecurePay: Tokenisation error', errors);
      this.$element.trigger('securepay:tokeniseError', [errors]);
      this.displayTokenisationErrors(errors);
      this.setButtonProcessing(this.$element.find('.webform-securepay-button'), false);
    }

    handleLoadComplete() {
      console.log('SecurePay: UI Component loaded successfully');
      this.$element.trigger('securepay:loadComplete');
      this.hideLoadingIndicator();
      this.$element.removeClass('loading').addClass('ready');
    }

    // Payment Processing
    processTokenisedCard(tokenisedCard) {
      const paymentData = {
        token: tokenisedCard.token,
        amount: this.settings.amount,
        merchantCode: tokenisedCard.merchantCode,
        scheme: tokenisedCard.scheme,
        last4: tokenisedCard.last4,
        expiryMonth: tokenisedCard.expiryMonth,
        expiryYear: tokenisedCard.expiryYear
      };

      if (tokenisedCard.dccQuote) {
        paymentData.dccQuote = tokenisedCard.dccQuote;
      }

      $.ajax({
        url: this.settings.paymentCallbackUrl || '/webform/securepay/callback',
        method: 'POST',
        data: JSON.stringify(paymentData),
        contentType: 'application/json',
        success: (response) => this.handlePaymentResponse(response),
        error: () => {
          console.error('SecurePay: Payment callback failed');
          this.displayError('Payment processing failed. Please try again.');
          this.setButtonProcessing(this.$element.find('.webform-securepay-button'), false);
        }
      });
    }

    handlePaymentResponse(response) {
      if (response.success) {
        this.displaySuccess(response);
        this.$element.trigger('securepay:paymentSuccess', [response]);
      } else {
        this.displayError(response.error || 'Payment failed. Please try again.');
        this.setButtonProcessing(this.$element.find('.webform-securepay-button'), false);
        this.$element.trigger('securepay:paymentError', [response]);
      }
    }

    // UI Updates
    updateCardTypeDisplay(cardType) {
      const $cardType = this.$element.find('.card-type-display');
      if ($cardType.length) {
        $cardType.text(cardType.charAt(0).toUpperCase() + cardType.slice(1));
        $cardType.removeClass().addClass('card-type-display card-type-' + cardType);
      }
    }

    updateSubmitButtonState(valid) {
      const $button = this.$element.find('.webform-securepay-button');
      $button.prop('disabled', !valid).toggleClass('invalid', !valid);
    }

    displayDCCOptions(quote) {
      const $dccOptions = this.$element.find('.dcc-options');
      if ($dccOptions.length && quote.converted) {
        const html = `
          <div class="dcc-option">
            <h4>${Drupal.t('Currency Options')}</h4>
            <p>${Drupal.t('You can pay in your card currency:')}</p>
            <p><strong>${quote.converted.currency} ${this.formatAmount(quote.converted.amount, quote.converted.currency)}</strong></p>
            <p>${Drupal.t('Exchange rate: 1 AUD = @rate @currency', {
              '@rate': quote.converted.exchangeRate.value,
              '@currency': quote.converted.currency
            })}</p>
          </div>
        `;
        $dccOptions.html(html).show();
      }
    }

    displaySuccess(response) {
      const $results = this.$element.find('.webform-securepay-result');
      $results.removeClass('error').addClass('success');
      $results.html(`
        <div class="transaction-id"><strong>${Drupal.t('Transaction ID')}:</strong> ${response.transaction_id}</div>
        <div class="status"><strong>${Drupal.t('Status')}:</strong> ${response.status}</div>
        <div class="amount"><strong>${Drupal.t('Amount')}:</strong> ${this.formatAmount(response.amount, response.currency)}</div>
      `);
      $results.show();
      this.hideLoadingIndicator();
    }

    displayError(message) {
      const $results = this.$element.find('.webform-securepay-result');
      $results.removeClass('success').addClass('error');
      $results.html(`<div class="error-message">${message}</div>`);
      $results.show();
      this.hideLoadingIndicator();
    }

    displayTokenisationErrors(errors) {
      let errorMessages = [];
      
      if (Array.isArray(errors)) {
        errors.forEach(error => {
          errorMessages.push(error.detail || error.message || 'Unknown error');
        });
      } else if (errors.detail) {
        errorMessages.push(errors.detail);
      } else {
        errorMessages.push('Tokenisation failed. Please check your card details.');
      }
      
      this.displayError(errorMessages.join(', '));
    }

    // Utility Methods
    setButtonProcessing($button, processing) {
      if (processing) {
        $button.prop('disabled', true).val(Drupal.t('Processing...')).addClass('processing');
      } else {
        $button.prop('disabled', false).val(Drupal.t('Process Payment')).removeClass('processing invalid');
      }
    }

    clearResults() {
      this.$element.find('.webform-securepay-result').hide().removeClass('success error').empty();
    }

    showLoadingIndicator() {
      let $loading = this.$element.find('.loading-indicator');
      if (!$loading.length) {
        $loading = $(`<div class="loading-indicator">${Drupal.t('Processing...')}</div>`);
        this.$element.append($loading);
      }
      $loading.show();
    }

    hideLoadingIndicator() {
      this.$element.find('.loading-indicator').hide();
    }

    formatAmount(amount, currency = 'AUD') {
      const formatted = (amount / 100).toFixed(2);
      return `${currency} ${formatted}`;
    }

    setup3DS2() {
      if (!this.settings.threeDSOrderToken) {
        console.log('SecurePay: 3DS2 enabled but no order token provided');
        return;
      }

      // Load 3DS2 script if not already loaded
      if (!window.SecurePayThreedsUI) {
        const script = document.createElement('script');
        script.src = this.settings.threeDSSdkUrl;
        script.onload = () => this.initialize3DS2();
        document.head.appendChild(script);
      } else {
        this.initialize3DS2();
      }
    }

    initialize3DS2() {
      const $iframe = $('<iframe id="3ds-v2-challenge-iframe" name="3ds-v2-challenge-iframe" style="width: 500px; height: 500px; visibility: hidden;"></iframe>');
      this.$element.append($iframe);

      const sp3dsConfig = {
        clientId: this.settings.threeDSClientId,
        iframe: $iframe[0],
        token: this.settings.threeDSOrderToken,
        simpleToken: this.settings.threeDSSimpleToken,
        threeDSSessionId: this.settings.threeDSSessionId,
        
        onRequestInputData: () => this.get3DS2InputData(),
        onThreeDSResultsResponse: (result) => this.handle3DS2Result(result),
        onThreeDSError: (errors) => this.handle3DS2Error(errors)
      };

      const securePayThreedsUI = new window.SecurePayThreedsUI();
      securePayThreedsUI.initThreeDS(sp3dsConfig);
      this.$element.data('securePayThreedsUI', securePayThreedsUI);
    }

    get3DS2InputData() {
      return {
        cardTokenInfo: {
          cardholderName: this.settings.cardholderName || 'Test Cardholder',
          cardToken: this.settings.cardToken
        },
        accountData: {
          emailAddress: this.settings.emailAddress,
          mobilePhone: this.settings.mobilePhone
        },
        billingAddress: this.settings.billingAddress,
        shippingAddress: this.settings.shippingAddress,
        threeDSInfo: {
          threeDSReqAuthMethodInd: this.settings.threeDSReqAuthMethodInd || '02'
        }
      };
    }

    handle3DS2Result(result) {
      console.log('SecurePay: 3DS2 authentication result', result);
      this.$element.trigger('securepay:threeDSResult', [result]);
      this.$element.data('threeDSResult', result);
    }

    handle3DS2Error(errors) {
      console.error('SecurePay: 3DS2 authentication error', errors);
      this.$element.trigger('securepay:threeDSError', [errors]);
      this.displayError('3D Secure authentication failed. Please try again.');
    }
  }

})(jQuery, Drupal, drupalSettings);