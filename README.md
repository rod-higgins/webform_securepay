# WebForm SecurePay Module - Complete Integration

This Drupal module provides comprehensive SecurePay payment integration for Drupal Webforms using the modern SecurePay REST API with OAuth 2.0 authentication and all available features.

## 🚀 Features

### Core Payment Processing
- **Modern REST API**: Uses SecurePay's latest API with OAuth 2.0 authentication
- **UI Component Integration**: Complete SecurePay UI Component with all callbacks
- **Real-time Processing**: Secure, real-time payment processing
- **Multiple Currencies**: Support for AUD, USD, EUR, GBP, and more
- **Card Types**: Visa, Mastercard, American Express, Diners Club

### Advanced Features
- **Dynamic Currency Conversion (DCC)**: Allow customers to pay in their card currency
- **3D Secure 2**: Enhanced authentication with 3DS2 protocol
- **Fraud Detection**: FraudGuard and ACI ReD Shield integration
- **Apple Pay**: Ready for Apple Pay integration (future release)
- **PayPal**: Ready for PayPal integration (future release)

### Security & Compliance
- **PCI DSS Compliant**: Minimizes PCI scope with secure iframe integration
- **3D Secure 2**: Advanced fraud protection and liability shift
- **SSL/TLS**: Encrypted communication with SecurePay
- **Webhook Verification**: Secure webhook processing with signature validation
- **Rate Limiting**: Protection against payment abuse

### UI & Customization
- **Fully Customizable**: Complete control over styling and appearance
- **Responsive Design**: Mobile-friendly payment forms
- **Dark Mode Support**: Automatic dark mode detection
- **Accessibility**: WCAG compliant with keyboard navigation
- **Multi-language**: Translation-ready with i18n support

### Administration & Reporting
- **Transaction Logging**: Comprehensive transaction tracking
- **Real-time Reports**: Dashboard with payment analytics
- **Webhook Management**: Real-time payment status updates
- **Refund Processing**: Built-in refund management
- **Export Features**: CSV/Excel export for accounting

### Developer Features
- **Event System**: Hooks and events for custom integrations
- **Custom Callbacks**: JavaScript event handling
- **API Integration**: RESTful endpoints for external systems
- **Debugging Tools**: Comprehensive logging and testing tools
- **Documentation**: Complete API documentation

## 📋 Requirements

### System Requirements
- **Drupal**: 10.4+ or 11+
- **PHP**: 8.1+ with cURL and JSON extensions
- **Webform**: Latest stable version
- **SSL**: Required for production environments

### SecurePay Requirements
- Active SecurePay merchant account
- Client ID and Client Secret (OAuth 2.0)
- Merchant Code
- Verified domains (for production)

## 🛠 Installation

### 1. Module Installation

```bash
# Via Composer (recommended)
composer require drupal/webform_securepay

# Or download and extract to modules/contrib/webform_securepay
```

### 2. Enable the Module

```bash
# Via Drush
drush en webform_securepay

# Or via admin interface: Extend > WebForm SecurePay
```

### 3. Configure Settings

Navigate to **Administration > Configuration > Web services > SecurePay Settings**

## ⚙️ Configuration

### Authentication Settings

```yaml
Client ID: your_client_id
Client Secret: your_client_secret  
Merchant Code: your_merchant_code
Environment: sandbox|live
```

### Payment Settings

```yaml
Default Currency: AUD
Payment Mode: checkout|dcc
Order ID Prefix: WF_
Allowed Card Types: [visa, mastercard, amex, diners]
```

### Feature Settings

```yaml
Dynamic Currency Conversion: enabled/disabled
3D Secure 2: enabled/disabled
Fraud Detection: enabled/disabled
Apple Pay: enabled/disabled (future)
PayPal: enabled/disabled (future)
```

### UI Customization

```yaml
Background Color: rgba(255, 255, 255, 0.1)
Font Family: Arial, Helvetica, sans-serif
Font Size: 1rem
Colors: customizable
```

## 🎯 Usage

### 1. Create a Webform

1. Go to **Structure > Webforms**
2. Create a new webform or edit existing
3. Add form fields as needed

### 2. Add SecurePay Element

1. Click **"Add element"**
2. Select **"SecurePay"** from Payment category
3. Configure the element:

```yaml
Element Key: payment
Title: Payment Details
Amount: 1000 (in cents) or [webform_submission:values:amount_field]
Currency: AUD
Mode: checkout
```

### 3. Configure Advanced Options

#### Dynamic Currency Conversion
```yaml
Mode: dcc
DCC Enabled: true
Supported Currencies: [USD, EUR, GBP, etc.]
```

#### 3D Secure 2
```yaml
3DS Enabled: true
Merchant Name: Your Business Name
Challenge Window Size: 05 (fullscreen)
```

#### Fraud Detection
```yaml
Fraud Check: FraudGuard|ACI_FRAUD_CHECK
Score Threshold: 75
Block High Risk: true
```

### 4. Test Integration

1. Use the **"Test Connection"** feature
2. Process test transactions in sandbox mode
3. Verify webhook endpoints
4. Test all payment flows

## 🔧 Advanced Configuration

### Custom Styling

Add custom CSS to override default styles:

```css
.webform-securepay-element {
  border: 2px solid #007cba;
  border-radius: 12px;
  background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
}

.webform-securepay-button {
  background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
  font-size: 18px;
  padding: 15px 30px;
}
```

### Custom JavaScript Events

```javascript
$(document).on('securepay:tokeniseSuccess', function(event, data) {
  console.log('Payment token received:', data.token);
  // Custom processing
});

$(document).on('securepay:paymentSuccess', function(event, result) {
  console.log('Payment successful:', result);
  // Redirect or show success message
  window.location.href = '/thank-you';
});

$(document).on('securepay:paymentError', function(event, error) {
  console.error('Payment failed:', error);
  // Custom error handling
});
```

### Webhook Configuration

```php
// In settings.php or settings.local.php
$config['webform_securepay.settings']['webhook_enabled'] = TRUE;
$config['webform_securepay.settings']['webhook_secret'] = 'your_webhook_secret';
```

Set up webhook endpoint: `https://yoursite.com/webform/securepay/webhook`

### API Integration

Use the service for custom integrations:

```php
$securepay_api = \Drupal::service('webform_securepay.api');

// Process payment
$result = $securepay_api->processPayment([
  'token' => $card_token,
  'amount' => 1000,
  'currency' => 'AUD',
  'order_id' => 'ORDER_123'
]);

// Process refund
$refund = $securepay_api->refundPayment('ORDER_123', 500);
```

## 📊 Reporting & Analytics

### Transaction Reports

Access comprehensive reporting at:
**Administration > Reports > SecurePay Transactions**

Features:
- Real-time transaction status
- Payment analytics and trends
- Export to CSV/Excel
- Fraud detection reports
- 3DS2 authentication reports

### Dashboard Widgets

Add payment widgets to your admin dashboard:
- Daily transaction summary
- Revenue charts
- Failed payment alerts
- Fraud detection alerts

## 🔒 Security Best Practices

### Production Checklist

- [ ] Enable SSL/HTTPS
- [ ] Use live environment credentials
- [ ] Enable fraud detection
- [ ] Configure 3D Secure 2
- [ ] Set up webhooks
- [ ] Enable transaction logging
- [ ] Configure data retention
- [ ] Test all payment flows
- [ ] Set up monitoring alerts

### PCI Compliance

This module minimizes PCI scope by:
- Using SecurePay's hosted payment fields
- Never storing card data on your server
- Implementing tokenization
- Following PCI DSS guidelines

### Data Protection

- Card data never touches your server
- Tokens expire after 30 minutes
- Transaction logs can be automatically purged
- GDPR compliance features available

## 🔍 Troubleshooting

### Common Issues

#### Authentication Errors
```
Error: Invalid client credentials
```
**Solution**: Verify Client ID and Client Secret in settings

#### Payment Failures
```
Error: Payment declined by bank
```
**Solution**: Check with customer or try different card

#### 3DS2 Issues
```
Error: 3DS authentication failed
```
**Solution**: Verify 3DS2 configuration and test cards

#### Webhook Problems
```
Error: Invalid webhook signature
```
**Solution**: Verify webhook secret and SSL certificate

### Debug Mode

Enable debug mode for detailed logging:

```php
$config['webform_securepay.settings']['debug_mode'] = TRUE;
```

View logs at: **Administration > Reports > Recent log messages**

### Test Cards

Use these test cards in sandbox mode:

| Card Type | Number | Result |
|-----------|--------|--------|
| Visa | 4111111111111111 | Success |
| Visa | 4000000000000002 | Decline |
| Mastercard | 5555555555554444 | Success |
| Amex | 378282246310005 | Success |

## 📚 API Documentation

### REST Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/webform/securepay/callback` | POST | Payment processing |
| `/webform/securepay/webhook` | POST | Webhook receiver |
| `/webform/securepay/refund/{order_id}` | POST | Process refund |
| `/webform/securepay/status/{order_id}` | GET | Payment status |

### JavaScript Events

| Event | Description | Data |
|-------|-------------|------|
| `securepay:loadComplete` | UI loaded | - |
| `securepay:cardTypeChange` | Card type detected | `cardType` |
| `securepay:formValidityChange` | Form validation | `valid` |
| `securepay:tokeniseSuccess` | Card tokenized | `tokenData` |
| `securepay:paymentSuccess` | Payment completed | `result` |
| `securepay:paymentError` | Payment failed | `error` |

### PHP Hooks

```php
// Payment success hook
function mymodule_webform_securepay_payment_success($submission, $payment_data) {
  // Custom processing after successful payment
}

// Payment failure hook  
function mymodule_webform_securepay_payment_failed($submission, $error) {
  // Custom processing after failed payment
}
```

## 🤝 Support

### Documentation
- [SecurePay API Documentation](https://auspost.com.au/payments/docs/securepay/)
- [Module Documentation](https://www.drupal.org/project/webform_securepay)

### Community Support
- [Drupal Issue Queue](https://www.drupal.org/project/issues/webform_securepay)
- [Drupal Slack #webform](https://drupal.slack.com/)

### Commercial Support
- Module maintenance and custom development available
- Enterprise support plans available
- Integration consulting services

### SecurePay Support
- [SecurePay Support Portal](https://www.securepay.com.au/support/)
- Phone: 1800 889 450
- Email: support@securepay.com.au

## 📝 Changelog

### Version 2.0.0 (Latest)
- Complete rewrite for modern SecurePay REST API
- OAuth 2.0 authentication
- All SecurePay UI Component callbacks implemented
- Dynamic Currency Conversion support
- 3D Secure 2 integration
- Fraud detection (FraudGuard & ACI ReD Shield)
- Comprehensive transaction logging
- Webhook support
- Enhanced security features
- Mobile-responsive design
- Accessibility improvements
- Multi-language support

### Migration from 1.x
See `UPGRADE.md` for detailed migration instructions.

## 📜 License

This project is licensed under the GPL-2.0+ License - see the [LICENSE](LICENSE) file for details.

## 🙏 Credits

- **SecurePay API**: Australia Post payment services
- **Drupal Webform**: Jacob Rockowitz and contributors
- **Module Maintainers**: [Your name/organization]

---

**⚠️ Important**: Always test thoroughly in sandbox mode before going live. Ensure your SSL certificate is valid and your domain is verified with SecurePay for production use.