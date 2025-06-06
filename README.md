# WebForm SecurePay Module

A clean, simple SecurePay payment integration for Drupal Webforms.

## Features

- SecurePay REST API integration with OAuth 2.0
- Webform element for payment processing  
- Transaction logging
- Dynamic Currency Conversion (DCC) support
- 3D Secure 2 authentication
- Responsive payment forms

## Requirements

- Drupal 10.1+ or 11+
- Webform module
- SecurePay merchant account

## Installation

1. Install via Composer: `composer require drupal/webform_securepay`
2. Enable the module: `drush en webform_securepay`
3. Configure at: Admin > Config > Webform > SecurePay Settings

## Configuration

1. **Credentials**: Enter your SecurePay Client ID, Client Secret, and Merchant Code
2. **Environment**: Choose Sandbox for testing, Live for production
3. **Currency**: Set your default payment currency
4. **Features**: Enable DCC and 3D Secure as needed

## Usage

1. Create or edit a webform
2. Add a "SecurePay" element
3. Configure the payment amount and currency
4. Publish your form

## Security

- All card data is handled by SecurePay (PCI compliant)
- Payments use secure tokens
- SSL/HTTPS required for production

## Support

For issues and feature requests, use the Drupal.org issue queue.

## License

GPL-2.0+