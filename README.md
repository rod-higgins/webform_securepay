# WebForm SecurePay Module

This Drupal module provides SecurePay payment integration for Drupal Webforms.

## Installation

1. Copy the module to your Drupal modules directory
2. Enable the module through the admin interface or via drush
3. Configure the module at `/admin/config/webform/securepay`

## Configuration

- Set your SecurePay merchant credentials
- Configure default currency and test mode
- Set API timeouts and logging preferences

## Usage

1. Create or edit a webform
2. Add a SecurePay element to your form
3. Configure payment amount and other settings
4. Test the integration using the test connection feature

## Files Structure

- `webform_securepay.info.yml` - Module definition
- `webform_securepay.module` - Main module hooks
- `webform_securepay.routing.yml` - Route definitions
- `webform_securepay.install` - Install/uninstall hooks
- `webform_securepay.services.yml` - Service definitions
- `webform_securepay.libraries.yml` - Asset library definitions
- `src/Plugin/WebformElement/WebformSecurePay.php` - Main webform element plugin
- `src/Form/WebformSecurePaySettingsForm.php` - Admin settings form
- `src/Element/WebformSecurePay.php` - Form element class
- `src/Service/SecurePayApiService.php` - API service for SecurePay integration
- `src/Controller/SecurePayController.php` - Controller for payment callbacks
- `config/schema/webform_securepay.schema.yml` - Configuration schema
- `templates/webform-securepay-element.html.twig` - Twig template
- `css/webform-securepay.css` - Styles
- `js/webform-securepay.js` - JavaScript behaviors

## Requirements

- Drupal 10.4+ or 11+
- Webform module
- GuzzleHTTP client (included in Drupal core)

## Security Notes

- Always use HTTPS in production
- Test thoroughly in test mode before going live
- Store sensitive credentials securely
- Regularly update the module and dependencies

