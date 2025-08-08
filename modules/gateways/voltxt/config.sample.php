<?php
/**
 * VOLTXT Configuration Template
 * 
 * Copy this file to config.php and customize for your environment.
 * Add the include line to your WHMCS configuration.php if needed.
 */

// Uncomment to enable debug logging
// define('VOLTXT_DEBUG', true);

// Uncomment to override default timeouts
// define('VOLTXT_TIMEOUT', 30);
// define('VOLTXT_CONNECT_TIMEOUT', 10);

// Uncomment to set custom webhook secret (if VOLTXT provides this feature)
// define('VOLTXT_WEBHOOK_SECRET', 'your-webhook-secret-here');

/**
 * Network-specific configurations
 */
$voltxtConfig = [
    'testnet' => [
        'api_url' => 'https://api.voltxt.io/api/plugin',
        'app_url' => 'https://app.voltxt.io',
        'explorer_url' => 'https://explorer.solana.com',
        'network_param' => '?cluster=testnet',
    ],
    'mainnet' => [
        'api_url' => 'https://api.voltxt.io/api/plugin',
        'app_url' => 'https://app.voltxt.io',
        'explorer_url' => 'https://explorer.solana.com',
        'network_param' => '',
    ]
];

/**
 * Error message customizations
 */
$voltxtErrorMessages = [
    'gateway_not_configured' => 'Cryptocurrency payment is temporarily unavailable. Please contact support.',
    'payment_failed' => 'Payment could not be processed. Please try again or use an alternative payment method.',
    'network_error' => 'Connection to payment service failed. Please try again in a few moments.',
    'invalid_amount' => 'Invalid payment amount. Please contact support.',
    'expired_invoice' => 'Payment session has expired. Please create a new order.',
];

/**
 * Customer instruction customizations
 */
$voltxtInstructions = [
    'testnet' => [
        'title' => 'Test Payment Instructions',
        'description' => 'This is a test transaction using Solana Testnet. No real cryptocurrency is required.',
        'steps' => [
            'Click the payment button to open the test payment page',
            'You will receive testnet SOL to complete the transaction',
            'Send the exact amount shown to the provided address',
            'Your test order will be processed automatically'
        ]
    ],
    'mainnet' => [
        'title' => 'Cryptocurrency Payment Instructions',
        'description' => 'Complete your payment using Solana (SOL) cryptocurrency.',
        'steps' => [
            'Click the payment button to open the secure payment page',
            'Send the exact SOL amount to the provided wallet address',
            'Payment confirmation typically takes 1-3 minutes',
            'Your order will be processed automatically once confirmed'
        ]
    ]
];

/**
 * Admin interface customizations
 */
$voltxtAdminConfig = [
    'show_network_badges' => true,
    'show_transaction_links' => true,
    'show_refresh_button' => true,
    'auto_refresh_interval' => 0, // 0 = disabled, or seconds for auto-refresh
    'max_log_entries' => 1000, // Maximum log entries to keep
];

/**
 * Webhook processing options
 */
$voltxtWebhookConfig = [
    'validate_signature' => true,
    'require_https' => true, // Require HTTPS for webhook endpoint
    'log_all_webhooks' => false, // Log all webhooks (even successful ones)
    'retry_failed_processing' => true,
    'max_processing_time' => 30, // Maximum webhook processing time in seconds
];

/**
 * Currency and amount configurations
 */
$voltxtCurrencyConfig = [
    'supported_currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'], // Add your supported currencies
    'minimum_amount' => [
        'USD' => 1.00,
        'EUR' => 1.00,
        'GBP' => 1.00,
        'CAD' => 1.50,
        'AUD' => 1.50,
    ],
    'maximum_amount' => [
        'USD' => 10000.00,
        'EUR' => 10000.00,
        'GBP' => 10000.00,
        'CAD' => 15000.00,
        'AUD' => 15000.00,
    ],
];

/**
 * Performance and caching options
 */
$voltxtPerformanceConfig = [
    'cache_exchange_rates' => true,
    'cache_duration' => 300, // Cache duration in seconds (5 minutes)
    'connection_pool' => false, // Use connection pooling if available
    'compress_requests' => true, // Enable gzip compression for API requests
];

/**
 * Email notification settings
 */
$voltxtEmailConfig = [
    'notify_admin_on_overpayment' => true,
    'notify_admin_on_failure' => true,
    'notify_customer_on_confirmation' => false, // WHMCS handles this
    'admin_notification_email' => '', // Leave empty to use WHMCS default
];

/**
 * Security settings
 */
$voltxtSecurityConfig = [
    'require_invoice_validation' => true,
    'validate_customer_ip' => false, // Validate customer IP against invoice
    'block_tor_exits' => false, // Block known Tor exit nodes
    'rate_limit_webhooks' => true,
    'webhook_rate_limit' => 100, // Max webhooks per minute per IP
];

/**
 * Logging and monitoring
 */
$voltxtLoggingConfig = [
    'log_level' => 'INFO', // DEBUG, INFO, WARNING, ERROR
    'log_api_requests' => false, // Log all API requests (sensitive data masked)
    'log_webhook_payloads' => true, // Log webhook payloads for debugging
    'log_file_path' => '', // Custom log file path, empty for default
    'max_log_file_size' => '10MB', // Maximum log file size before rotation
];

/**
 * Custom field mappings (optional)
 * Map WHMCS custom fields to VOLTXT metadata
 */
$voltxtCustomFieldMapping = [
    // 'whmcs_custom_field_name' => 'voltxt_metadata_key',
    // 'customer_reference' => 'customer_ref',
    // 'department' => 'dept_code',
];

/**
 * Invoice customization
 */
$voltxtInvoiceConfig = [
    'description_template' => 'Invoice #{invoiceid} - {companyname}',
    'include_line_items' => false, // Include invoice line items in description
    'max_description_length' => 255,
    'custom_metadata' => [
        // Add custom metadata to all invoices
        'whmcs_version' => 'auto', // 'auto' to detect, or specify version
        'integration_version' => '1.0.0',
        'site_name' => 'auto', // 'auto' to use company name, or specify
    ],
];

/**
 * Backup and recovery settings
 */
$voltxtBackupConfig = [
    'backup_failed_webhooks' => true,
    'backup_retention_days' => 30,
    'auto_retry_failed_payments' => false,
    'retry_attempts' => 3,
    'retry_delay' => 300, // Seconds between retry attempts
];

/**
 * Development and testing options
 */
$voltxtDevelopmentConfig = [
    'force_testnet' => false, // Force testnet mode regardless of settings
    'mock_api_responses' => false, // Use mock responses for testing
    'disable_webhooks' => false, // Disable webhook processing
    'simulate_payment_delays' => false, // Simulate blockchain confirmation delays
];

/**
 * Feature flags
 */
$voltxtFeatureFlags = [
    'enable_admin_hooks' => true,
    'enable_client_area_enhancements' => true,
    'enable_status_refresh' => true,
    'enable_transaction_links' => true,
    'enable_network_badges' => true,
    'enable_payment_instructions' => true,
];

/**
 * API client configuration
 */
$voltxtApiConfig = [
    'user_agent' => 'WHMCS-VOLTXT-Gateway/1.0',
    'max_redirects' => 3,
    'verify_ssl' => true,
    'ssl_cipher_list' => 'HIGH:!aNULL:!eNULL:!EXPORT:!DES:!RC4:!MD5:!PSK:!SRP:!CAMELLIA',
    'dns_cache_timeout' => 300,
    'keep_alive' => true,
];

/**
 * Maintenance and cleanup
 */
$voltxtMaintenanceConfig = [
    'cleanup_expired_invoices' => true,
    'cleanup_after_days' => 90, // Clean up invoices older than X days
    'archive_old_transactions' => false,
    'optimize_database_tables' => false, // Optimize VOLTXT tables periodically
];

/**
 * Integration-specific settings
 */
$voltxtIntegrationConfig = [
    'whmcs_version_check' => true, // Check WHMCS version compatibility
    'php_version_check' => true, // Check PHP version compatibility
    'required_extensions' => ['curl', 'json', 'openssl'], // Required PHP extensions
    'recommended_extensions' => ['mbstring', 'intl'], // Recommended extensions
];

/**
 * Error handling and recovery
 */
$voltxtErrorHandlingConfig = [
    'graceful_degradation' => true, // Fail gracefully when possible
    'fallback_payment_methods' => [], // Array of fallback gateway names
    'error_notification_threshold' => 5, // Notify admin after X consecutive errors
    'auto_disable_on_errors' => false, // Auto-disable gateway after too many errors
    'error_count_reset_time' => 3600, // Reset error count after X seconds
];

/**
 * Compliance and regulatory settings
 */
$voltxtComplianceConfig = [
    'gdpr_compliance' => true, // Enable GDPR compliance features
    'data_retention_period' => 2555, // Days to retain customer data (7 years)
    'anonymize_expired_data' => true, // Anonymize old customer data
    'audit_trail' => true, // Maintain audit trail of all transactions
    'pci_dss_mode' => false, // Enable PCI DSS compliance mode (if applicable)
];

// Apply configurations if this file is included
if (defined('WHMCS')) {
    // Apply debug setting
    if (isset($voltxtLoggingConfig['log_level']) && $voltxtLoggingConfig['log_level'] === 'DEBUG') {
        if (!defined('VOLTXT_DEBUG')) {
            define('VOLTXT_DEBUG', true);
        }
    }
    
    // Apply timeout settings
    if (isset($voltxtApiConfig['timeout']) && !defined('VOLTXT_TIMEOUT')) {
        define('VOLTXT_TIMEOUT', $voltxtApiConfig['timeout']);
    }
    
    if (isset($voltxtApiConfig['connect_timeout']) && !defined('VOLTXT_CONNECT_TIMEOUT')) {
        define('VOLTXT_CONNECT_TIMEOUT', $voltxtApiConfig['connect_timeout']);
    }
    
    // Store configurations in global scope for access by the gateway
    $GLOBALS['voltxt_config'] = [
        'general' => $voltxtConfig ?? [],
        'errors' => $voltxtErrorMessages ?? [],
        'instructions' => $voltxtInstructions ?? [],
        'admin' => $voltxtAdminConfig ?? [],
        'webhook' => $voltxtWebhookConfig ?? [],
        'currency' => $voltxtCurrencyConfig ?? [],
        'performance' => $voltxtPerformanceConfig ?? [],
        'email' => $voltxtEmailConfig ?? [],
        'security' => $voltxtSecurityConfig ?? [],
        'logging' => $voltxtLoggingConfig ?? [],
        'custom_fields' => $voltxtCustomFieldMapping ?? [],
        'invoice' => $voltxtInvoiceConfig ?? [],
        'backup' => $voltxtBackupConfig ?? [],
        'development' => $voltxtDevelopmentConfig ?? [],
        'features' => $voltxtFeatureFlags ?? [],
        'api' => $voltxtApiConfig ?? [],
        'maintenance' => $voltxtMaintenanceConfig ?? [],
        'integration' => $voltxtIntegrationConfig ?? [],
        'error_handling' => $voltxtErrorHandlingConfig ?? [],
        'compliance' => $voltxtComplianceConfig ?? [],
    ];
}