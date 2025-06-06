(function ($, Drupal) {
  'use strict';

  /**
   * SecurePay Admin Form Behavior
   */
  Drupal.behaviors.webformSecurePayAdmin = {
    attach: function (context, settings) {
      $('.form-item-security-use-key-module input[type="checkbox"]', context)
        .once('webform-securepay-admin')
        .each(function () {
          const $checkbox = $(this);
          const $form = $checkbox.closest('form');
          
          // Initialize form state
          this.updateFormState = function() {
            const useKeyModule = $checkbox.is(':checked');
            toggleCredentialModes(useKeyModule, $form);
          };
          
          // Set initial state
          this.updateFormState();
          
          // Handle checkbox changes
          $checkbox.on('change', this.updateFormState);
        });
    }
  };

  /**
   * Toggle between key module and direct credential input modes
   */
  function toggleCredentialModes(useKeyModule, $form) {
    // Key module fields
    const keyFields = [
      '.form-item-credentials-client-id-key',
      '.form-item-credentials-client-secret-key',
      '.form-item-credentials-merchant-code-key'
    ];
    
    // Direct input fields
    const directFields = [
      '.form-item-credentials-client-id',
      '.form-item-credentials-client-secret',
      '.form-item-credentials-merchant-code'
    ];
    
    if (useKeyModule) {
      // Show key fields, hide direct fields
      showFields(keyFields, $form);
      hideFields(directFields, $form);
      
      // Clear direct input values for security
      clearDirectInputs($form);
      
      // Update help text
      updateHelpText($form, true);
    } else {
      // Show direct fields, hide key fields
      showFields(directFields, $form);
      hideFields(keyFields, $form);
      
      // Update help text
      updateHelpText($form, false);
    }
    
    // Update form validation requirements
    updateValidationRequirements(useKeyModule, $form);
  }

  /**
   * Show specified fields with animation
   */
  function showFields(fieldSelectors, $form) {
    fieldSelectors.forEach(function(selector) {
      const $field = $form.find(selector);
      if ($field.length) {
        $field.removeClass('webform-securepay-field-hidden')
              .addClass('webform-securepay-field-visible')
              .show();
        
        // Re-enable form elements
        $field.find('input, select').prop('disabled', false);
      }
    });
  }

  /**
   * Hide specified fields with animation
   */
  function hideFields(fieldSelectors, $form) {
    fieldSelectors.forEach(function(selector) {
      const $field = $form.find(selector);
      if ($field.length) {
        $field.removeClass('webform-securepay-field-visible')
              .addClass('webform-securepay-field-hidden');
        
        // Disable form elements to prevent submission
        $field.find('input, select').prop('disabled', true);
        
        // Hide after transition
        setTimeout(function() {
          if ($field.hasClass('webform-securepay-field-hidden')) {
            $field.hide();
          }
        }, 300);
      }
    });
  }

  /**
   * Clear direct input values for security
   */
  function clearDirectInputs($form) {
    const directInputs = [
      'input[name="credentials[client_id]"]',
      'input[name="credentials[client_secret]"]',
      'input[name="credentials[merchant_code]"]'
    ];
    
    directInputs.forEach(function(selector) {
      $form.find(selector).val('');
    });
  }

  /**
   * Update help text based on credential mode
   */
  function updateHelpText($form, useKeyModule) {
    const $helpSection = $form.find('.webform-securepay-help');
    
    if ($helpSection.length) {
      let helpText = '<p>To configure SecurePay:</p><ol>';
      helpText += '<li>Log into your SecurePay merchant dashboard</li>';
      helpText += '<li>Navigate to API settings</li>';
      helpText += '<li>Create OAuth 2.0 credentials</li>';
      
      if (useKeyModule) {
        helpText += '<li>Create Key entities for your credentials using the <a href="/admin/config/system/keys">Key management interface</a></li>';
        helpText += '<li>Select the appropriate keys below</li>';
      } else {
        helpText += '<li>Copy the Client ID, Client Secret, and Merchant Code below</li>';
      }
      
      helpText += '<li>Test the connection before going live</li></ol>';
      
      if (useKeyModule) {
        helpText += '<p class="webform-securepay-security-notice">';
        helpText += '<strong>Enhanced Security:</strong> Using the Key module provides secure credential storage and is recommended for production environments.';
        helpText += '</p>';
      }
      
      $helpSection.find('div').html(helpText);
    }
  }

  /**
   * Update form validation requirements
   */
  function updateValidationRequirements(useKeyModule, $form) {
    if (useKeyModule) {
      // Remove required attribute from direct inputs
      $form.find('input[name="credentials[client_id]"]').removeAttr('required');
      $form.find('input[name="credentials[merchant_code]"]').removeAttr('required');
      
      // Add required attribute to key selects
      $form.find('select[name="credentials[client_id_key]"]').attr('required', 'required');
      $form.find('select[name="credentials[client_secret_key]"]').attr('required', 'required');
      $form.find('select[name="credentials[merchant_code_key]"]').attr('required', 'required');
    } else {
      // Add required attribute to direct inputs
      $form.find('input[name="credentials[client_id]"]').attr('required', 'required');
      $form.find('input[name="credentials[merchant_code]"]').attr('required', 'required');
      
      // Remove required attribute from key selects
      $form.find('select[name="credentials[client_id_key]"]').removeAttr('required');
      $form.find('select[name="credentials[client_secret_key]"]').removeAttr('required');
      $form.find('select[name="credentials[merchant_code_key]"]').removeAttr('required');
    }
  }

  /**
   * Connection Test Enhancement
   */
  Drupal.behaviors.webformSecurePayConnectionTest = {
    attach: function (context, settings) {
      $('.webform-securepay-test-button', context)
        .once('webform-securepay-test')
        .each(function () {
          const $button = $(this);
          const originalText = $button.val();
          
          $button.on('click', function(e) {
            // Disable button and show loading state
            $button.prop('disabled', true)
                   .val('Testing Connection...')
                   .addClass('webform-securepay-testing');
            
            // Re-enable button after form submission
            setTimeout(function() {
              $button.prop('disabled', false)
                     .val(originalText)
                     .removeClass('webform-securepay-testing');
            }, 3000);
          });
        });
    }
  };

  /**
   * Form Enhancement for Better UX
   */
  Drupal.behaviors.webformSecurePayFormEnhancement = {
    attach: function (context, settings) {
      // Add visual indicators for required fields in key mode
      $('.form-item-credentials-client-id-key select,' +
        '.form-item-credentials-client-secret-key select,' +
        '.form-item-credentials-merchant-code-key select', context)
        .once('webform-securepay-key-indicators')
        .each(function () {
          const $select = $(this);
          
          $select.on('change', function() {
            const $formItem = $select.closest('.form-item');
            
            if ($select.val() && $select.val() !== '_none') {
              $formItem.addClass('webform-securepay-field-completed');
            } else {
              $formItem.removeClass('webform-securepay-field-completed');
            }
          });
          
          // Check initial state
          $select.trigger('change');
        });
      
      // Environment warning for live mode
      $('select[name="credentials[environment]"]', context)
        .once('webform-securepay-env-warning')
        .each(function () {
          const $select = $(this);
          const $formItem = $select.closest('.form-item');
          
          $select.on('change', function() {
            const isLive = $select.val() === 'live';
            
            if (isLive) {
              if (!$formItem.find('.webform-securepay-live-warning').length) {
                const warningHtml = '<div class="webform-securepay-live-warning messages messages--warning">' +
                  '<strong>Warning:</strong> Live environment requires HTTPS and will process real payments.' +
                  '</div>';
                $formItem.append(warningHtml);
              }
            } else {
              $formItem.find('.webform-securepay-live-warning').remove();
            }
          });
          
          // Check initial state
          $select.trigger('change');
        });
    }
  };

  /**
   * Accessibility Improvements
   */
  Drupal.behaviors.webformSecurePayA11y = {
    attach: function (context, settings) {
      // Add ARIA labels and descriptions
      $('.form-item-security-use-key-module input[type="checkbox"]', context)
        .once('webform-securepay-a11y')
        .each(function () {
          const $checkbox = $(this);
          
          $checkbox.attr('aria-describedby', 'webform-securepay-key-module-description');
          
          // Add description element if it doesn't exist
          if (!$('#webform-securepay-key-module-description').length) {
            const descriptionHtml = '<div id="webform-securepay-key-module-description" class="visually-hidden">' +
              'Enable this option to use the Key module for secure credential storage instead of direct input fields.' +
              '</div>';
            $checkbox.closest('.form-item').append(descriptionHtml);
          }
        });
      
      // Add live region for connection test results
      if (!$('#webform-securepay-test-results').length) {
        $('body').append('<div id="webform-securepay-test-results" aria-live="polite" class="visually-hidden"></div>');
      }
    }
  };

})(jQuery, Drupal);