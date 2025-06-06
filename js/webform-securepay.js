(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.webformSecurePay = {
    attach: function (context, settings) {
      $('.webform-securepay-button', context).once('webform-securepay').each(function () {
        var $button = $(this);
        
        $button.on('click', function (e) {
          // Add loading state
          $button.prop('disabled', true);
          $button.val(Drupal.t('Processing...'));
          
          // You can add additional client-side validation here
          
          // The form submission will be handled by Drupal's AJAX system
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);