(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.webformSecurePay = {
    attach: function (context, settings) {
      $('.webform-securepay-element', context).once('webform-securepay').each(function () {
        var $element = $(this);
        var elementSettings = settings.webformSecurePay || {};
        var $container = $element.find('.securepay-ui-container');
        var containerId = $container.attr('id');
        
        if (!containerId) {
          console.error('SecurePay: Container ID not found');
          return;
        }

        // Initialize SecurePay UI Component
        initializeSecurePayUI($element, elementSettings, containerId);
      });
    }
  };

  /**
   * Initialize SecurePay UI Component with all callbacks and configuration.
   */
  function initializeSecurePayUI($element, settings, containerId) {
    // Wait for SecurePay script to load
    if (typeof securePayUI === 'undefined') {
      setTimeout(function() {
        initializeSecurePayUI($element, settings, containerId);
      }, 100);
      return;
    }

    try {
      // Build UI configuration object with all available options
      var uiConfig = {
        containerId: containerId,
        scriptId: 'securepay-ui-js',
        clientId: settings.clientId,
        merchantCode: settings.merchantCode,
        mode: settings.mode || 'checkout', // 'checkout' or 'dcc'
        
        // Card configuration with all available options
        card: {
          allowedCardTypes: settings.allowedCardTypes || ['visa', 'mastercard', 'amex', 'diners'],
          showCardIcons: settings.showCardIcons !== false,
          
          // Card type change callback
          onCardTypeChange: function(cardType) {
            console.log('SecurePay: Card type changed to', cardType);
            $element.trigger('securepay:cardTypeChange', [cardType]);
            
            // Update UI to show card type
            updateCardTypeDisplay($element, cardType);
          },
          
          // BIN change callback
          onBINChange: function(cardBIN) {
            console.log('SecurePay: Card BIN changed to', cardBIN);
            $element.trigger('securepay:binChange', [cardBIN]);
            
            // Trigger BIN-specific logic (e.g., fraud checks)
            handleBINChange($element, cardBIN, settings);
          },
          
          // Form validity change callback
          onFormValidityChange: function(valid) {
            console.log('SecurePay: Form validity changed to', valid);
            $element.trigger('securepay:formValidityChange', [valid]);
            
            // Enable/disable submit button based on form validity
            updateSubmitButtonState($element, valid);
          },
          
          // DCC quote success callback (for DCC mode)
          onDCCQuoteSuccess: function(quote) {
            console.log('SecurePay: DCC quote success', quote);
            $element.trigger('securepay:dccQuoteSuccess', [quote]);
            
            // Display currency conversion options to user
            displayDCCOptions($element, quote);
            
            // Enable tokenization after DCC quote
            enableTokenization($element);
          },
          
          // DCC quote error callback (for DCC mode)
          onDCCQuoteError: function(errors) {
            console.error('SecurePay: DCC quote error', errors);
            $element.trigger('securepay:dccQuoteError', [errors]);
            
            // Display error message
            displayError($element, 'Currency conversion unavailable. Please try again.');
          },
          
          // Tokenise success callback
          onTokeniseSuccess: function(tokenisedCard) {
            console.log('SecurePay: Tokenisation successful', tokenisedCard);
            $element.trigger('securepay:tokeniseSuccess', [tokenisedCard]);
            
            // Process the tokenised card data
            processTokenisedCard($element, tokenisedCard, settings);
          },
          
          // Tokenise error callback
          onTokeniseError: function(errors) {
            console.error('SecurePay: Tokenisation error', errors);
            $element.trigger('securepay:tokeniseError', [errors]);
            
            // Display error messages
            displayTokenisationErrors($element, errors);
            
            // Re-enable the payment button
            resetPaymentButton($element);
          }
        },
        
        // Style configuration with all available options
        style: {
          backgroundColor: settings.backgroundColor || 'rgba(255, 255, 255, 0.1)',
          label: {
            font: {
              family: settings.labelFontFamily || 'Arial, Helvetica, sans-serif',
              size: settings.labelFontSize || '1rem',
              color: settings.labelColor || '#333'
            }
          },
          input: {
            font: {
              family: settings.inputFontFamily || 'Arial, Helvetica, sans-serif',
              size: settings.inputFontSize || '1rem',
              color: settings.inputColor || '#333'
            }
          }
        },
        
        // Checkout info for DCC mode
        checkoutInfo: settings.mode === 'dcc' && settings.orderToken ? {
          orderToken: settings.orderToken
        } : undefined,
        
        // Global load complete callback
        onLoadComplete: function() {
          console.log('SecurePay: UI Component loaded successfully');
          $element.trigger('securepay:loadComplete');
          
          // Hide loading indicator
          hideLoadingIndicator($element);
          
          // Enable the component
          enableComponent($element);
          
          // Focus on first field if configured
          if (settings.autoFocus) {
            focusFirstField($element);
          }
        }
      };

      // Initialize the SecurePay UI Component
      var securePayUIInstance = new securePayUI.init(uiConfig);
      
      // Store instance reference for later use
      $element.data('securePayUIInstance', securePayUIInstance);
      
      // Set up payment button click handler
      setupPaymentButton($element, securePayUIInstance, settings);
      
      // Set up reset button click handler
      setupResetButton($element, securePayUIInstance);
      
      // Set up 3DS2 if enabled
      if (settings.threeDSEnabled) {
        setup3DS2($element, settings);
      }
      
    } catch (error) {
      console.error('SecurePay: Failed to initialize UI Component', error);
      displayError($element, 'Payment system unavailable. Please try again later.');
    }
  }

  /**
   * Set up payment button click handler.
   */
  function setupPaymentButton($element, securePayUIInstance, settings) {
    var $button = $element.find('.webform-securepay-button');
    
    $button.on('click', function(e) {
      e.preventDefault();
      
      // Disable button and show processing state
      $button.prop('disabled', true);
      $button.val(Drupal.t('Processing...'));
      $button.addClass('processing');
      
      // Show loading indicator
      showLoadingIndicator($element);
      
      // Clear previous results
      clearResults($element);
      
      try {
        // Trigger tokenisation
        securePayUIInstance.tokenise();
        
        $element.trigger('securepay:tokeniseInitiated');
        
      } catch (error) {
        console.error('SecurePay: Failed to tokenise', error);
        displayError($element, 'Payment processing failed. Please try again.');
        resetPaymentButton($element);
      }
    });
  }

  /**
   * Set up reset button click handler.
   */
  function setupResetButton($element, securePayUIInstance) {
    var $resetButton = $element.find('.webform-securepay-reset');
    
    if ($resetButton.length) {
      $resetButton.on('click', function(e) {
        e.preventDefault();
        
        try {
          // Reset the form
          securePayUIInstance.reset();
          
          // Clear results and reset UI
          clearResults($element);
          resetPaymentButton($element);
          
          $element.trigger('securepay:formReset');
          
        } catch (error) {
          console.error('SecurePay: Failed to reset form', error);
        }
      });
    }
  }

  /**
   * Process tokenised card data.
   */
  function processTokenisedCard($element, tokenisedCard, settings) {
    var paymentData = {
      token: tokenisedCard.token,
      amount: settings.amount,
      merchantCode: tokenisedCard.merchantCode,
      scheme: tokenisedCard.scheme,
      last4: tokenisedCard.last4,
      expiryMonth: tokenisedCard.expiryMonth,
      expiryYear: tokenisedCard.expiryYear
    };

    // Add DCC quote data if present
    if (tokenisedCard.dccQuote) {
      paymentData.dccQuote = tokenisedCard.dccQuote;
    }

    // Send to server for payment processing
    $.ajax({
      url: settings.paymentCallbackUrl || '/webform/securepay/callback',
      method: 'POST',
      data: JSON.stringify(paymentData),
      contentType: 'application/json',
      success: function(response) {
        handlePaymentResponse($element, response);
      },
      error: function(xhr, status, error) {
        console.error('SecurePay: Payment callback failed', error);
        displayError($element, 'Payment processing failed. Please try again.');
        resetPaymentButton($element);
      }
    });
  }

  /**
   * Handle payment response from server.
   */
  function handlePaymentResponse($element, response) {
    if (response.success) {
      displaySuccess($element, response);
      $element.trigger('securepay:paymentSuccess', [response]);
    } else {
      displayError($element, response.error || 'Payment failed. Please try again.');
      resetPaymentButton($element);
      $element.trigger('securepay:paymentError', [response]);
    }
  }

  /**
   * Display success message and results.
   */
  function displaySuccess($element, response) {
    var $results = $element.find('.webform-securepay-result');
    
    $results.removeClass('error').addClass('success');
    $results.html(
      '<div class="transaction-id"><strong>' + Drupal.t('Transaction ID') + ':</strong> ' + response.transaction_id + '</div>' +
      '<div class="status"><strong>' + Drupal.t('Status') + ':</strong> ' + response.status + '</div>' +
      '<div class="amount"><strong>' + Drupal.t('Amount') + ':</strong> ' + formatAmount(response.amount, response.currency) + '</div>'
    );
    
    $results.show();
    hideLoadingIndicator($element);
  }

  /**
   * Display error message.
   */
  function displayError($element, message) {
    var $results = $element.find('.webform-securepay-result');
    
    $results.removeClass('success').addClass('error');
    $results.html('<div class="error-message">' + message + '</div>');
    $results.show();
    
    hideLoadingIndicator($element);
  }

  /**
   * Display tokenisation errors.
   */
  function displayTokenisationErrors($element, errors) {
    var errorMessages = [];
    
    if (Array.isArray(errors)) {
      errors.forEach(function(error) {
        errorMessages.push(error.detail || error.message || 'Unknown error');
      });
    } else if (errors.detail) {
      errorMessages.push(errors.detail);
    } else {
      errorMessages.push('Tokenisation failed. Please check your card details.');
    }
    
    displayError($element, errorMessages.join(', '));
  }

  /**
   * Update card type display.
   */
  function updateCardTypeDisplay($element, cardType) {
    var $cardType = $element.find('.card-type-display');
    
    if ($cardType.length) {
      $cardType.text(cardType.charAt(0).toUpperCase() + cardType.slice(1));
      $cardType.removeClass().addClass('card-type-display card-type-' + cardType);
    }
  }

  /**
   * Handle BIN change for fraud detection or other BIN-specific logic.
   */
  function handleBINChange($element, cardBIN, settings) {
    // You can implement BIN-specific logic here
    // For example, fraud detection, card issuer identification, etc.
    
    if (settings.binCheckEnabled && cardBIN.length >= 6) {
      // Perform BIN check or other validation
      console.log('SecurePay: Performing BIN check for', cardBIN);
    }
  }

  /**
   * Update submit button state based on form validity.
   */
  function updateSubmitButtonState($element, valid) {
    var $button = $element.find('.webform-securepay-button');
    
    if (valid) {
      $button.prop('disabled', false);
      $button.removeClass('invalid');
    } else {
      $button.prop('disabled', true);
      $button.addClass('invalid');
    }
  }

  /**
   * Display DCC options to user.
   */
  function displayDCCOptions($element, quote) {
    var $dccOptions = $element.find('.dcc-options');
    
    if ($dccOptions.length && quote.converted) {
      var html = '<div class="dcc-option">' +
        '<h4>' + Drupal.t('Currency Options') + '</h4>' +
        '<p>' + Drupal.t('You can pay in your card currency:') + '</p>' +
        '<p><strong>' + quote.converted.currency + ' ' + formatAmount(quote.converted.amount, quote.converted.currency) + '</strong></p>' +
        '<p>' + Drupal.t('Exchange rate: 1 AUD = @rate @currency', {
          '@rate': quote.converted.exchangeRate.value,
          '@currency': quote.converted.currency
        }) + '</p>' +
        '<p>' + Drupal.t('Including @markup% margin', {
          '@markup': quote.converted.exchangeRate.markup
        }) + '</p>' +
        '</div>';
      
      $dccOptions.html(html).show();
    }
  }

  /**
   * Enable tokenization (used after DCC quote success).
   */
  function enableTokenization($element) {
    var $button = $element.find('.webform-securepay-button');
    $button.prop('disabled', false);
    $button.text(Drupal.t('Complete Payment'));
  }

  /**
   * Reset payment button to initial state.
   */
  function resetPaymentButton($element) {
    var $button = $element.find('.webform-securepay-button');
    
    $button.prop('disabled', false);
    $button.val(Drupal.t('Process Payment'));
    $button.removeClass('processing invalid');
    
    hideLoadingIndicator($element);
  }

  /**
   * Clear results display.
   */
  function clearResults($element) {
    var $results = $element.find('.webform-securepay-result');
    $results.hide().removeClass('success error').empty();
  }

  /**
   * Show loading indicator.
   */
  function showLoadingIndicator($element) {
    var $loading = $element.find('.loading-indicator');
    if (!$loading.length) {
      $loading = $('<div class="loading-indicator">' + Drupal.t('Processing...') + '</div>');
      $element.append($loading);
    }
    $loading.show();
  }

  /**
   * Hide loading indicator.
   */
  function hideLoadingIndicator($element) {
    var $loading = $element.find('.loading-indicator');
    $loading.hide();
  }

  /**
   * Enable component after successful load.
   */
  function enableComponent($element) {
    $element.removeClass('loading').addClass('ready');
  }

  /**
   * Focus on first field if auto-focus is enabled.
   */
  function focusFirstField($element) {
    // The actual focus will be handled by the SecurePay iframe
    // This is just a placeholder for any additional logic
  }

  /**
   * Set up 3DS2 integration.
   */
  function setup3DS2($element, settings) {
    if (!settings.threeDSOrderToken) {
      console.log('SecurePay: 3DS2 enabled but no order token provided');
      return;
    }

    // Load 3DS2 script if not already loaded
    if (!window.SecurePayThreedsUI) {
      var script = document.createElement('script');
      script.src = settings.threeDSSdkUrl;
      script.onload = function() {
        initialize3DS2($element, settings);
      };
      document.head.appendChild(script);
    } else {
      initialize3DS2($element, settings);
    }
  }

  /**
   * Initialize 3DS2.
   */
  function initialize3DS2($element, settings) {
    // Create iframe for 3DS2 challenge
    var $iframe = $('<iframe id="3ds-v2-challenge-iframe" name="3ds-v2-challenge-iframe" style="width: 500px; height: 500px; visibility: hidden;"></iframe>');
    $element.append($iframe);

    var sp3dsConfig = {
      clientId: settings.threeDSClientId,
      iframe: $iframe[0],
      token: settings.threeDSOrderToken,
      simpleToken: settings.threeDSSimpleToken,
      threeDSSessionId: settings.threeDSSessionId,
      
      onRequestInputData: function() {
        return get3DS2InputData($element, settings);
      },
      
      onThreeDSResultsResponse: function(result) {
        handle3DS2Result($element, result);
      },
      
      onThreeDSError: function(errors) {
        handle3DS2Error($element, errors);
      }
    };

    var securePayThreedsUI = new window.SecurePayThreedsUI();
    securePayThreedsUI.initThreeDS(sp3dsConfig);
    
    $element.data('securePayThreedsUI', securePayThreedsUI);
  }

  /**
   * Get 3DS2 input data.
   */
  function get3DS2InputData($element, settings) {
    // Return the data required for 3DS2 authentication
    return {
      cardTokenInfo: {
        cardholderName: settings.cardholderName || 'Test Cardholder',
        cardToken: settings.cardToken
      },
      accountData: {
        emailAddress: settings.emailAddress,
        mobilePhone: settings.mobilePhone ? {
          cc: settings.mobilePhone.cc || '+61',
          subscriber: settings.mobilePhone.subscriber
        } : undefined
      },
      billingAddress: settings.billingAddress,
      shippingAddress: settings.shippingAddress,
      threeDSInfo: {
        threeDSReqAuthMethodInd: settings.threeDSReqAuthMethodInd || '02'
      }
    };
  }

  /**
   * Handle 3DS2 result.
   */
  function handle3DS2Result($element, result) {
    console.log('SecurePay: 3DS2 authentication result', result);
    $element.trigger('securepay:threeDSResult', [result]);
    
    // Store 3DS2 result for payment processing
    $element.data('threeDSResult', result);
  }

  /**
   * Handle 3DS2 error.
   */
  function handle3DS2Error($element, errors) {
    console.error('SecurePay: 3DS2 authentication error', errors);
    $element.trigger('securepay:threeDSError', [errors]);
    
    displayError($element, '3D Secure authentication failed. Please try again.');
  }

  /**
   * Format amount for display.
   */
  function formatAmount(amount, currency) {
    currency = currency || 'AUD';
    var formatted = (amount / 100).toFixed(2);
    return currency + ' ' + formatted;
  }

})(jQuery, Drupal, drupalSettings);