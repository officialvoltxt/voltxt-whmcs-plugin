# Voltxt Solana Payment Gateway for WHMCS

A secure, fast, and easy-to-integrate Solana cryptocurrency payment gateway for WHMCS that enables businesses to accept SOL payments with real-time processing and automatic invoice management.

## Features

- **Dynamic Payment Processing**: Real-time SOL price conversion with automatic payment detection
- **Secure PDA Generation**: Uses Solana Program Derived Addresses for enhanced security
- **Automatic Invoice Management**: Payments are automatically applied to WHMCS invoices
- **Real-time Status Updates**: Live payment tracking with Server-Sent Events
- **Multi-network Support**: Works on both Solana mainnet and testnet
- **Webhook Integration**: Reliable payment notifications via webhooks
- **Payment Expiration**: Configurable payment timeouts (1-168 hours)
- **Session Management**: Prevents duplicate payments with intelligent session handling

## Requirements

- WHMCS 7.8 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher
- SSL certificate (required for production)
- Voltxt API account (sign up at [app.voltxt.io](https://app.voltxt.io))

## Installation

### 1. Download and Extract

Download the Voltxt payment gateway files and extract them to your WHMCS installation directory.

### 2. Upload Files

Upload the following files to your WHMCS installation:

```
/modules/gateways/voltxt.php
/modules/gateways/callback/voltxt.php
```

### 3. File Permissions

Ensure the uploaded files have the correct permissions:

```bash
chmod 644 /path/to/whmcs/modules/gateways/voltxt.php
chmod 644 /path/to/whmcs/modules/gateways/callback/voltxt.php
```

### 4. Database Setup

The plugin will automatically create the required database table (`mod_voltxt_sessions`) when first used.

## Configuration

### 1. Enable the Gateway

1. Log in to your WHMCS Admin Area
2. Go to **Setup** > **Payments** > **Payment Gateways**
3. Click on **All Payment Gateways**
4. Find "Voltxt Solana Payment Gateway" and click **Activate**

### 2. Configure Settings

Fill in the following configuration fields:

#### API Key
- **Required**: Your 32-character Voltxt API key
- **Where to get it**: [app.voltxt.io](https://app.voltxt.io) > Dashboard > API Keys
- **Format**: Exactly 32 characters (letters and numbers)

#### Network
- **Testnet**: Use for testing and development
- **Mainnet**: Use for live payments
- **Note**: Your API key must match the selected network

#### Payment Expiry (Hours)
- **Default**: 24 hours
- **Range**: 1-168 hours (1 week maximum)
- **Recommendation**: 24-48 hours for most use cases

#### Debug Mode
- **Enable**: For troubleshooting and development
- **Disable**: For production use
- **Note**: Creates detailed logs in WHMCS Activity Log

### 3. Test Connection

1. Enter your API key and select network
2. Click **Test API Connection**
3. Verify connection shows "Connected! Store: [Your Store Name]"
4. Save configuration

## Usage

### For Administrators

#### Payment Processing Flow
1. Customer selects Voltxt payment method at checkout
2. WHMCS creates payment session via Voltxt API
3. Customer is redirected to secure Voltxt payment page
4. Customer completes payment using Solana wallet
5. Voltxt processes payment and sends webhook to WHMCS
6. WHMCS automatically marks invoice as paid
7. Customer is redirected back to WHMCS with confirmation

#### Monitoring Payments
- **Activity Log**: Setup > Logs > Activity Log (search "Voltxt")
- **Gateway Log**: Setup > Logs > Gateway Log
- **Invoice History**: View individual invoice payment records

### For Customers

#### Payment Process
1. Select "Pay with Solana" at checkout
2. Click "Pay with Solana Wallet" button
3. Scan QR code or copy payment details
4. Send exact SOL amount to provided address
5. Wait for blockchain confirmation
6. Automatic redirect back to invoice

#### Supported Wallets
- Phantom Wallet
- Solflare
- Ledger Hardware Wallets
- Any Solana-compatible wallet

## Webhooks

### Webhook URL
The plugin automatically configures webhooks using:
```
https://yourdomain.com/modules/gateways/callback/voltxt.php
```

### Webhook Events
- `payment_completed`: Payment successfully processed
- `payment_cancelled`: Payment cancelled by user
- `payment_expired`: Payment session expired

### Webhook Security
- Webhooks include signature verification
- All webhook data is validated before processing
- Failed webhooks are logged for debugging

## Troubleshooting

### Common Issues

#### "Invalid API key configuration"
- Verify API key is exactly 32 characters
- Ensure API key matches selected network (testnet/mainnet)
- Check API key permissions in Voltxt dashboard

#### "Payment session not found"
- Check if payment expired (default 24 hours)
- Verify webhook URL is accessible
- Check WHMCS activity logs for errors

#### "Connection test failed"
- Verify internet connectivity
- Check if firewall blocks outbound HTTPS
- Ensure SSL certificate is valid

#### Payment not marked as paid
- Check WHMCS Activity Log for webhook receipt
- Verify webhook URL is publicly accessible
- Ensure payment amount matches invoice total

### Debug Mode

Enable debug mode for detailed logging:

1. Go to gateway configuration
2. Enable "Debug Mode"
3. Save configuration
4. Check Setup > Logs > Activity Log for detailed events

### Log Locations

- **Activity Log**: Setup > Logs > Activity Log (search "Voltxt")
- **Gateway Log**: Setup > Logs > Gateway Log
- **PHP Error Log**: Check server error logs for PHP errors

## Security Considerations

### SSL Requirements
- SSL certificate is required for production use
- Webhooks require HTTPS endpoints
- Payment pages use encrypted connections

### API Key Security
- Store API keys securely
- Use testnet for development
- Rotate API keys periodically
- Never expose API keys in client-side code

### Network Security
- Whitelist Voltxt webhook IPs if using firewall
- Monitor for unusual payment patterns
- Regular security updates for WHMCS

## Support

### Documentation
- [Voltxt API Documentation](https://docs.voltxt.io)
- [WHMCS Developer Docs](https://developers.whmcs.com)

### Getting Help

#### Voltxt Support
- **Email**: support@voltxt.io
- **Documentation**: [docs.voltxt.io](https://docs.voltxt.io)
- **Dashboard**: [app.voltxt.io](https://app.voltxt.io)

#### WHMCS Support
- Check WHMCS system logs first
- Provide payment session IDs when reporting issues
- Include relevant log entries

### Reporting Issues

When reporting issues, please include:
- WHMCS version
- PHP version
- Error messages from logs
- Payment session ID (if applicable)
- Steps to reproduce the issue

## Changelog

### Version 1.0.0
- Initial release
- Dynamic payment processing
- Webhook integration
- Session management
- Real-time payment tracking
- Multi-network support

## License

This plugin is provided under the MIT License. See LICENSE file for details.

## Disclaimer

This software is provided "as is" without warranty. Always test thoroughly on testnet before using in production. Cryptocurrency payments are irreversible - ensure proper testing and validation.

---

**Voltxt** - Simplifying Solana payments for businesses worldwide.

For more information, visit [voltxt.io](https://voltxt.io)