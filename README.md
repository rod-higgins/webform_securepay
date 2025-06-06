# WebForm SecurePay Module

A comprehensive, secure SecurePay payment integration for Drupal Webforms using OAuth 2.0 authentication.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Security](#security)
- [API Reference](#api-reference)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

## Features

### Core Functionality
- **Secure Payment Processing**: PCI-compliant payment processing via SecurePay API
- **OAuth 2.0 Authentication**: Secure API authentication with automatic token management
- **Multi-Currency Support**: AUD, USD, EUR, GBP currency support
- **Responsive UI**: Mobile-friendly payment forms with modern styling
- **Transaction Logging**: Comprehensive audit trail for all payment transactions

### Advanced Features
- **Dynamic Currency Conversion (DCC)**: Allow customers to pay in their card currency
- **3D Secure 2**: Enhanced authentication for fraud protection
- **Sandbox & Live Environments**: Safe testing environment with easy production switch
- **Rate Limiting**: Built-in protection against abuse and excessive requests
- **Error Handling**: Comprehensive error handling with user-friendly messages
- **Webhook Support**: Real-time payment status notifications

### Developer Features
- **Type Safety**: Full PHP 8.1+ type hints for better IDE support
- **Exception Handling**: Structured exception hierarchy for better error management
- **Service Architecture**: Clean, testable service-oriented architecture
- **Configuration Schema**: Fully documented configuration with validation
- **Extensible Design**: Plugin-based architecture for easy customization

## Requirements

### System Requirements
- **Drupal**: 10.1+ or 11.x
- **PHP**: 8.1 or higher
- **Webform Module**: 6.1 or higher
- **SSL/HTTPS**: Required for live environment
- **JavaScript**: Required for payment form functionality

### PHP Extensions
- `json` - JSON processing
- `curl` - HTTP client communication
- `openssl` - SSL/TLS encryption

### SecurePay Account
- Active SecurePay merchant account
- OAuth 2.0 credentials (Client ID and Client Secret)
- Merchant Code

## Installation

### Via Composer (Recommended)
```bash
composer require drupal/webform_securepay
drush en webform_securepay
```

### Manual Installation
1. Download the module from Drupal.org
2. Extract to `modules/contrib/webform_securepay`
3. Enable via admin interface or Drush:
   ```bash
   drush en webform_securepay
   ```

### Post-Installation
1. Clear caches: `drush cr`
2. Configure module: Navigate to `/admin/config/webform/securepay`
3. Test connection before going live

## Configuration

### Basic Setup

#### 1. Access Configuration
Navigate to: **Admin** → **Configuration** → **Webform** → **SecurePay Settings**
URL: `/admin/config/webform/securepay`

#### 2. OAuth Credentials
- **Client ID**: Your SecurePay OAuth Client ID
- **Client Secret**: Your SecurePay OAuth Client Secret  
- **Merchant Code**: Your SecurePay merchant identifier

#### 3. Environment Settings
- **Sandbox**: For testing (uses test.auspost.com.au)
- **Live**: For production (requires HTTPS)

#### 4. Payment Settings
- **Default Currency**: AUD, USD, EUR, or GBP
- **DCC Enabled**: Enable Dynamic Currency Conversion
- **3D Secure**: Enable 3D Secure 2 authentication

### Advanced Configuration

#### Environment Variables
For enhanced security, use environment variables:
```php
// settings.php
$config['webform_securepay.settings']['client_secret'] = $_ENV['SECUREPAY_CLIENT_SECRET'];
```

#### Custom Timeout Settings
```php
// settings.php
$config['webform_securepay.settings']['api_timeout'] = 30;
$config['webform_securepay.settings']['connect_timeout'] = 10;
```

#### Transaction Log Retention
```php
// settings.php
$config['webform_securepay.settings']['log_retention_days'] = 365;
```

## Usage

### Adding Payment Elements

#### 1. Create/Edit Webform
- Navigate to **Structure** → **Webforms**
- Create new or edit existing webform

#### 2. Add SecurePay Element
- Click **Add element**
- Select **SecurePay** from the Payment category
- Configure element settings:
  - **Amount**: Payment amount in cents (e.g., 1000 = $10.00)
  - **Currency**: Override default currency if needed
  - **Mode**: Standard checkout or DCC

#### 3. Element Configuration Examples

**Fixed Amount:**
```yaml
amount: 5000  # $50.00
currency: AUD
```

**Dynamic Amount (using tokens):**
```yaml
amount: '[webform_submission:values:amount]'
currency: '[webform_submission:values:currency]'
```

**DCC Mode:**
```yaml
amount: 2500  # $25.00
currency: USD
mode: dcc
```

### Form Integration

#### Validation
Elements automatically validate:
- Amount ranges (1 cent to $99,999.99)
- Currency codes
- Payment tokens
- Required fields

#### Submission Handling
Successful payments store:
- Transaction ID
- Payment status
- Amount and currency
- Processing timestamp

### Testing

#### Sandbox Environment
- Use provided test credentials
- No real money is processed
- Full API functionality available
- Test card numbers available from SecurePay

#### Connection Testing
Use the built-in connection test:
1. Go to SecurePay settings
2. Click **Test Connection**
3. Verify credentials and network connectivity

## Security

### Data Protection
- **No Card Storage**: Card details never touch your server
- **Token-Based**: Secure tokenization prevents data exposure
- **PCI Compliance**: SecurePay handles PCI requirements
- **Input Sanitization**: All inputs validated and sanitized

### Network Security
- **HTTPS Required**: SSL/TLS encryption for live payments
- **Rate Limiting**: Protection against abuse
- **IP Validation**: Client IP logging for fraud detection
- **CSRF Protection**: Drupal's built-in CSRF protection

### Error Handling
- **Safe Error Messages**: No sensitive data in user-facing errors
- **Comprehensive Logging**: Detailed logs for administrators
- **Graceful Degradation**: Fallback behavior for failures

### Best Practices
1. **Environment Separation**: Always test in sandbox first
2. **Credential Security**: Use environment variables for secrets
3. **Regular Updates**: Keep module and dependencies updated
4. **Monitor Logs**: Review transaction logs regularly
5. **Access Control**: Restrict admin access appropriately

## API Reference

### Services

#### ConfigurationService
```php
\Drupal::service('webform_securepay.configuration');
```
- `isConfigured()`: Check if module is configured
- `get($key)`: Get configuration value
- `getApiEndpoints()`: Get environment-specific URLs

#### SecurePayApiService
```php
\Drupal::service('webform_securepay.api');
```
- `processPayment($data)`: Process payment transaction
- `testConnection()`: Test API connectivity
- `initiatePaymentOrder()`: Create DCC/3DS orders

#### PaymentService
```php
\Drupal::service('webform_securepay.payment');
```
- `processPayment($data)`: High-level payment processing
- `generateOrderId()`: Generate unique order IDs
- `getTransactionByOrderId($id)`: Retrieve transaction data

### Hooks

#### hook_webform_securepay_payment_alter()
```php
function mymodule_webform_securepay_payment_alter(&$payment_data, $context) {
  // Modify payment data before processing
  $payment_data['custom_field'] = 'value';
}
```

#### hook_webform_securepay_transaction_complete()
```php
function mymodule_webform_securepay_transaction_complete($transaction_data) {
  // React to completed transactions
  \Drupal::logger('mymodule')->info('Payment completed: @id', [
    '@id' => $transaction_data['transaction_id']
  ]);
}
```

### Events

The module dispatches Symfony events for advanced integration:

- `WebformSecurePayEvents::PAYMENT_SUCCESS`
- `WebformSecurePayEvents::PAYMENT_FAILED`
- `WebformSecurePayEvents::TRANSACTION_LOGGED`

## Troubleshooting

### Common Issues

#### 1. "Payment system not configured"
**Cause**: Missing or invalid credentials
**Solution**: 
- Verify all fields in SecurePay settings
- Test connection using built-in test
- Check SecurePay dashboard for credential accuracy

#### 2. "SSL/HTTPS required"
**Cause**: Attempting to use live environment without HTTPS
**Solution**:
- Enable SSL certificate on your domain
- Configure HTTPS redirects
- Use sandbox environment for testing

#### 3. "Connection timeout"
**Cause**: Network connectivity issues
**Solution**:
- Check firewall settings
- Verify DNS resolution
- Test from server command line: `curl https://api.payments.auspost.com.au`

#### 4. "Invalid token format"
**Cause**: Token validation failure
**Solution**:
- Clear browser cache
- Check JavaScript console for errors
- Verify SecurePay SDK is loading

### Debugging

#### Enable Debug Logging
```php
// settings.local.php
$config['system.logging']['error_level'] = 'verbose';
```

#### Database Queries
Check transaction logs:
```sql
SELECT * FROM webform_securepay_transactions 
WHERE created > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY))
ORDER BY created DESC;
```

#### Log Analysis
Monitor logs at: **Reports** → **Recent log messages**
Filter by: `webform_securepay`

### Performance Optimization

#### Database Indexing
The module automatically creates optimized indexes. If experiencing slow queries:
```bash
drush updb  # Ensure all updates are applied
```

#### Caching
API tokens are automatically cached. To clear:
```bash
drush state:delete webform_securepay.access_token
```

## Contributing

### Development Setup
1. Fork the repository
2. Create feature branch: `git checkout -b feature/amazing-feature`
3. Install dependencies: `composer install`
4. Run tests: `./vendor/bin/phpunit`
5. Commit changes: `git commit -m 'Add amazing feature'`
6. Push branch: `git push origin feature/amazing-feature`
7. Create Pull Request

### Coding Standards
- Follow Drupal coding standards
- Use PHP 8.1+ type hints
- Write comprehensive tests
- Document all public methods
- Update CHANGELOG.md

### Testing
```bash
# Run PHPUnit tests
./vendor/bin/phpunit

# Code quality checks
./vendor/bin/phpcs --standard=Drupal .
./vendor/bin/phpstan analyse

# JavaScript tests
npm test
```

## Changelog

### Version 1.0.0 (Current)
- Initial release
- OAuth 2.0 integration
- Multi-currency support
- DCC and 3DS2 support
- Comprehensive security features
- Full Drupal 10/11 compatibility

## License

This project is licensed under the GPL-2.0+ License - see the [LICENSE](LICENSE) file for details.

## Support

### Documentation
- [Module Documentation](https://www.drupal.org/project/webform_securepay)
- [SecurePay API Documentation](https://developers.auspost.com.au/apis/pacpay)
- [Webform Module Documentation](https://www.drupal.org/docs/contributed-modules/webform)

### Community Support
- [Issue Queue](https://www.drupal.org/project/issues/webform_securepay)
- [Drupal Slack](https://drupal.slack.com) - #webform channel
- [Stack Overflow](https://stackoverflow.com/questions/tagged/drupal-webform)

### Commercial Support
For enterprise support, customization, or consulting services, contact the module maintainers through the Drupal.org project page.

---

**Maintained by**: [Rod Higgins]  
**Project Page**: https://www.drupal.org/project/webform_securepay  
**Issue Queue**: https://www.drupal.org/project/issues/webform_securepay