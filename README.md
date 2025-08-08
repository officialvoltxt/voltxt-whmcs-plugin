# VOLTXT WHMCS Gateway Plugin

Accept Solana (SOL) payments in WHMCS with real-time pricing and instant processing.

## Features

- **Dynamic Payment Sessions** - Real-time SOL pricing with instant processing
- **Dual Network Support** - Testnet for development, Mainnet for production
- **Auto-Processing** - Payments processed automatically on confirmation
- **Live Connection Testing** - Built-in API connectivity verification
- **Comprehensive Admin Interface** - Payment tracking with blockchain explorer links
- **Webhook Support** - Real-time payment status updates

## Requirements

- WHMCS 8.0+
- PHP 7.4 - 8.4
- MySQL/MariaDB
- SSL Certificate (for webhooks)
- VOLTXT Account with API access

## Installation

1. **Upload Files**
   ```
   modules/gateways/voltxt.php
   modules/gateways/voltxt/
   modules/gateways/callback/voltxt.php
   ```

2. **Set Permissions**
   ```bash
   chmod 644 modules/gateways/voltxt.php
   chmod 644 modules/gateways/callback/voltxt.php
   chmod -R 755 modules/gateways/voltxt/
   ```

3. **Activate Gateway**
   - Login to WHMCS Admin
   - Go to `Setup → Payments → Payment Gateways`
   - Activate `VOLTXT Crypto Payments`

## Configuration

### Required Settings

| Field | Description |
|-------|-------------|
| **API URL** | `https://api.voltxt.io` |
| **API Key** | Your VOLTXT API key from dashboard |
| **Testnet Mode** | Enable for testing, disable for live transactions |

### Optional Settings

| Field | Description | Default |
|-------|-------------|---------|
| **Payment Expiry** | Hours until payment expires | 24 |
| **Show Instructions** | Display payment guidance to customers | On |

### Connection Testing

The gateway includes built-in connection testing that validates:
- API connectivity and credentials
- Network configuration (testnet/mainnet)
- Store setup and destination wallet
- Account permissions

## Webhook Configuration

Configure webhook URL in your VOLTXT dashboard:

```
https://yourdomain.com/modules/gateways/callback/voltxt.php
```

### Supported Events
- `payment_completed` - Payment successfully processed
- `payment_expired` - Payment session expired
- `overpayment_detected` - Customer sent too much SOL

## File Structure

```
modules/gateways/
├── voltxt.php                          # Main gateway module
├── voltxt/
│   ├── lib/
│   │   └── ApiClient.php              # VOLTXT API client
│   ├── hooks.php                      # Admin interface enhancements
│   ├── refresh_status.php             # Manual payment status refresh
│   ├── webhook_test.php               # Webhook testing tool
│   └── install.php                    # Installation verification
└── callback/
    └── voltxt.php                     # Webhook handler
```

## Testing

### 1. Test Installation
```bash
php modules/gateways/voltxt/install.php
```

### 2. Test Webhook
```bash
curl -X POST "https://yourdomain.com/modules/gateways/callback/voltxt.php" \
  -H "Content-Type: application/json" \
  -d '{
    "event_type": "payment_completed",
    "session_id": "dp_test123",
    "external_payment_id": "whmcs_invoice_123",
    "network": "testnet",
    "amount_fiat": 10.00,
    "payment_tx_id": "test_tx_12345"
  }'
```

### 3. Debug Tools
- **Connection Test**: Built into gateway settings
- **Webhook Test**: `modules/gateways/voltxt/webhook_test.php`
- **Status Refresh**: Available in admin invoice view

## Payment Flow

### Customer Experience
1. Customer selects VOLTXT payment method
2. Redirected to dynamic payment page with real Solana address
3. Sends exact SOL amount to provided address
4. Payment auto-processed on blockchain confirmation
5. Redirected back to store with payment confirmation

### Technical Flow
1. **Payment Initiation** → VOLTXT API creates dynamic session
2. **Address Generation** → Unique Solana PDA created for payment
3. **Customer Payment** → SOL sent to generated address
4. **Payment Detection** → VOLTXT monitors blockchain
5. **Webhook Delivery** → Payment status sent to WHMCS
6. **Auto-Processing** → Invoice marked paid, customer notified

## Troubleshooting

### Common Issues

**"Module Not Activated"**
- Ensure gateway is activated in WHMCS admin
- Check file permissions

**"Connection Failed"**
- Verify API URL and key
- Check network setting (testnet/mainnet)
- Ensure SSL certificate is valid

**"Webhook Not Received"**
- Verify webhook URL in VOLTXT dashboard
- Check server firewall settings
- Test webhook endpoint directly

**"Payment Not Recording"**
- Check WHMCS Activity Log for webhook entries
- Verify invoice ID format in webhook
- Ensure gateway name matches in WHMCS

### Debug Steps

1. **Check Gateway Configuration**
   ```
   Setup → Payments → Payment Gateways → VOLTXT
   ```

2. **Review Activity Logs**
   ```
   Utilities → Logs → Activity Log
   Search for: "VOLTXT"
   ```

3. **Check Gateway Logs**
   ```
   Utilities → Logs → Gateway Log
   Filter by: voltxt
   ```

4. **Test Webhook Manually**
   ```
   Access: modules/gateways/voltxt/webhook_test.php
   ```

## Support

- **Documentation**: [VOLTXT Documentation](https://docs.voltxt.io)
- **API Reference**: [VOLTXT API Docs](https://api.voltxt.io/docs)
- **GitHub Issues**: Report bugs and feature requests

## Security

- All payment data stored in admin-only invoice notes
- Webhook signature validation supported
- No sensitive data stored in visible areas
- PCI compliance not required (crypto payments)

## License

This plugin is provided under the MIT License. See LICENSE file for details.

## Changelog

### v2.0.0
- Dynamic payment sessions with real-time pricing
- Enhanced admin interface with payment tracking
- Improved webhook handling and error reporting
- Built-in connection testing and debugging tools
- Automatic payment processing on confirmation

### v1.0.0
- Initial release with basic Solana payment support